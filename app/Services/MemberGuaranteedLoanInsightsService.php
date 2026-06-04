<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Member\Resources\MyGuaranteedLoans\MyGuaranteedLoanResource;
use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Support\BusinessDay;
use App\Support\Insights\InsightFormatter;
use App\Support\Loans\LoanUserFacingStage;
use App\Support\Tenant\CurrentMember;
use Carbon\Carbon;

final class MemberGuaranteedLoanInsightsService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(?Member $member = null): array
    {
        $member = $member ?? CurrentMember::get();

        if ($member === null) {
            return [];
        }

        $base = Loan::query()->where('guarantor_member_id', $member->id);

        $total = (int) (clone $base)->count();
        $active = (int) (clone $base)->where('status', 'active')->count();
        $pending = (int) (clone $base)->whereIn('status', ['pending', 'approved', 'partially_disbursed'])->count();
        $liabilityTransferred = (int) (clone $base)
            ->whereNotNull('guarantor_liability_transferred_at')
            ->whereIn('status', ['active', 'transferred'])
            ->count();

        $outstanding = (float) LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->whereHas('loan', fn ($query) => $query
                ->where('guarantor_member_id', $member->id)
                ->whereIn('status', ['active', 'transferred']))
            ->sum('amount');

        $overdueEmis = (int) LoanInstallment::query()
            ->where('status', 'overdue')
            ->whereHas('loan', fn ($query) => $query
                ->where('guarantor_member_id', $member->id)
                ->where('status', 'active'))
            ->count();

        $atRisk = (int) Loan::query()
            ->where('guarantor_member_id', $member->id)
            ->where('status', 'active')
            ->whereNull('guarantor_liability_transferred_at')
            ->whereHas('installments', fn ($query) => $query->where('status', 'overdue'))
            ->where('late_repayment_count', '>=', Setting::loanDefaultGraceCycles())
            ->count();

        $sparkline = $this->monthlySparkline($member);
        $sparklineMax = max(1, max($sparkline));

        $firstAtRisk = $atRisk > 0
            ? Loan::query()
                ->where('guarantor_member_id', $member->id)
                ->where('status', 'active')
                ->whereNull('guarantor_liability_transferred_at')
                ->whereHas('installments', fn ($query) => $query->where('status', 'overdue'))
                ->where('late_repayment_count', '>=', Setting::loanDefaultGraceCycles())
                ->latest('applied_at')
                ->first()
            : null;

        return [
            'hero' => $this->buildHero(
                $total,
                $active,
                $liabilityTransferred,
                $overdueEmis,
                $atRisk,
                $firstAtRisk,
            ),
            'kpis' => $this->buildKpis(
                $total,
                $active,
                $pending,
                $liabilityTransferred,
                $outstanding,
                $overdueEmis,
                $atRisk,
            ),
            'sparkline' => $sparkline,
            'sparkline_max' => $sparklineMax,
            'exposure' => [
                'outstanding_emis' => InsightFormatter::money($outstanding),
                'overdue_emis' => (string) $overdueEmis,
                'liability_on_you' => (string) $liabilityTransferred,
                'at_risk_loans' => (string) $atRisk,
            ],
            'recent' => $this->recentLoans($member),
            'index_url' => MyGuaranteedLoanResource::getUrl('index'),
        ];
    }

    /**
     * @return array{tone: string, title: string, subtitle: string, cta_label?: string, cta_url?: string}
     */
    private function buildHero(
        int $total,
        int $active,
        int $liabilityTransferred,
        int $overdueEmis,
        int $atRisk,
        ?Loan $firstAtRisk,
    ): array {
        if ($liabilityTransferred > 0) {
            return [
                'tone' => 'danger',
                'title' => __('Guarantor liability is on you'),
                'subtitle' => trans_choice(
                    ':count loan has been transferred to your account|:count loans have been transferred to your account',
                    $liabilityTransferred,
                    ['count' => $liabilityTransferred],
                ),
                'cta_label' => __('Review loans'),
                'cta_url' => MyGuaranteedLoanResource::getUrl('index'),
            ];
        }

        if ($atRisk > 0) {
            return [
                'tone' => 'amber',
                'title' => __('Borrowers at default risk'),
                'subtitle' => trans_choice(
                    ':count guaranteed loan is past the grace threshold|:count guaranteed loans are past the grace threshold',
                    $atRisk,
                    ['count' => $atRisk],
                ),
                'cta_label' => __('View loan'),
                'cta_url' => $firstAtRisk !== null
                    ? MyGuaranteedLoanResource::getUrl('view', ['record' => $firstAtRisk])
                    : MyGuaranteedLoanResource::getUrl('index'),
            ];
        }

        if ($overdueEmis > 0) {
            return [
                'tone' => 'warning',
                'title' => __('Overdue repayments on guaranteed loans'),
                'subtitle' => trans_choice(
                    ':count EMI is overdue|:count EMIs are overdue',
                    $overdueEmis,
                    ['count' => $overdueEmis],
                ),
                'cta_label' => __('View guaranteed loans'),
                'cta_url' => MyGuaranteedLoanResource::getUrl('index'),
            ];
        }

        if ($active > 0) {
            return [
                'tone' => 'sky',
                'title' => __('Monitoring guaranteed loans'),
                'subtitle' => trans_choice(
                    'You guarantee :count active loan|You guarantee :count active loans',
                    $active,
                    ['count' => $active],
                ),
            ];
        }

        if ($total > 0) {
            return [
                'tone' => 'success',
                'title' => __('No active guarantor exposure'),
                'subtitle' => __('You have :count completed or closed guaranteed loan(s) on record.', ['count' => $total]),
            ];
        }

        return [
            'tone' => 'success',
            'title' => __('No guaranteed loans'),
            'subtitle' => __('You are not named as guarantor on any loan yet.'),
            'cta_label' => __('My loans'),
            'cta_url' => MyLoanResource::getUrl('index'),
        ];
    }

    /**
     * @return list<array{label: string, value: string, sub: string, accent: string, icon: string, url?: string|null, value_class?: string|null}>
     */
    private function buildKpis(
        int $total,
        int $active,
        int $pending,
        int $liabilityTransferred,
        float $outstanding,
        int $overdueEmis,
        int $atRisk,
    ): array {
        $indexUrl = MyGuaranteedLoanResource::getUrl('index');

        return [
            [
                'label' => __('Total'),
                'value' => (string) $total,
                'sub' => __('Guaranteed'),
                'accent' => 'sky',
                'icon' => 'heroicon-o-shield-check',
                'url' => $indexUrl,
            ],
            [
                'label' => __('Active'),
                'value' => (string) $active,
                'sub' => __('Repaying'),
                'accent' => 'emerald',
                'icon' => 'heroicon-o-banknotes',
                'url' => $indexUrl,
            ],
            [
                'label' => __('Pending'),
                'value' => (string) $pending,
                'sub' => __('Not yet active'),
                'accent' => 'amber',
                'icon' => 'heroicon-o-clock',
                'url' => $indexUrl,
            ],
            [
                'label' => __('On you'),
                'value' => (string) $liabilityTransferred,
                'sub' => __('Liability transferred'),
                'accent' => 'rose',
                'icon' => 'heroicon-o-exclamation-triangle',
                'url' => $indexUrl,
                'value_class' => $liabilityTransferred > 0
                    ? 'text-rose-700 dark:text-rose-300'
                    : 'text-gray-900 dark:text-white',
            ],
            [
                'label' => __('Outstanding'),
                'value' => InsightFormatter::money($outstanding),
                'sub' => __('Borrower EMIs due'),
                'accent' => 'violet',
                'icon' => 'heroicon-o-scale',
                'url' => $indexUrl,
            ],
            [
                'label' => __('At risk'),
                'value' => (string) $atRisk,
                'sub' => $overdueEmis > 0
                    ? trans_choice(':count overdue EMI|:count overdue EMIs', $overdueEmis, ['count' => $overdueEmis])
                    : __('Past grace'),
                'accent' => 'indigo',
                'icon' => 'heroicon-o-bolt',
                'url' => $indexUrl,
                'value_class' => $atRisk > 0
                    ? 'text-amber-700 dark:text-amber-300'
                    : 'text-gray-900 dark:text-white',
            ],
        ];
    }

    /**
     * @return list<array{id: int, borrower: string, amount: string, status_label: string, liability_label: string, view_url: string}>
     */
    private function recentLoans(Member $member): array
    {
        return Loan::query()
            ->where('guarantor_member_id', $member->id)
            ->with('member')
            ->latest('applied_at')
            ->limit(5)
            ->get()
            ->map(fn (Loan $loan): array => [
                'id' => $loan->id,
                'borrower' => $loan->member?->name ?? '—',
                'amount' => InsightFormatter::money((float) ($loan->amount_approved ?? $loan->amount_requested ?? 0)),
                'status_label' => LoanUserFacingStage::memberListStatusLabel($loan),
                'liability_label' => $loan->guarantor_liability_transferred_at !== null
                    ? __('On guarantor')
                    : __('On borrower'),
                'view_url' => MyGuaranteedLoanResource::getUrl('view', ['record' => $loan]),
            ])
            ->all();
    }

    /**
     * @return list<int>
     */
    private function monthlySparkline(Member $member): array
    {
        $now = BusinessDay::now();
        $oldestMonth = $now->copy()->subMonths(5)->startOfMonth();
        $monthCounts = [];

        Loan::query()
            ->where('guarantor_member_id', $member->id)
            ->whereBetween('applied_at', [$oldestMonth, $now->copy()->endOfMonth()])
            ->get(['applied_at'])
            ->each(function (Loan $loan) use (&$monthCounts): void {
                $appliedAt = $loan->applied_at;

                if ($appliedAt === null) {
                    return;
                }

                $key = Carbon::parse((string) $appliedAt)->startOfMonth()->format('Y-m');
                $monthCounts[$key] = ($monthCounts[$key] ?? 0) + 1;
            });

        $points = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i)->startOfMonth()->format('Y-m');
            $points[] = $monthCounts[$month] ?? 0;
        }

        return $points;
    }
}
