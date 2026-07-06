<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\Members\RelationManagers\LoansRelationManager;
use App\Filament\Tenant\Resources\Members\RelationManagers\MemberTransactionsTabsRelationManager;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\Insights\InsightFormatter;
use App\Support\TenantRuntimeCache;
use Carbon\Carbon;

final class MemberWorkspaceSummaryService
{
    public static function forgetCached(int $memberId): void
    {
        TenantRuntimeCache::forget(self::cacheKey($memberId));
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(Member $member): array
    {
        return TenantRuntimeCache::remember(
            self::cacheKey((int) $member->id),
            30,
            fn (): array => $this->compose($member),
        );
    }

    /**
     * Cheap arrears signal for header actions (no delinquency evaluator).
     */
    public function arrearsVisible(Member $member): bool
    {
        return (bool) ($this->summary($member)['arrears']['visible'] ?? false);
    }

    private static function cacheKey(int $memberId): string
    {
        return "member_workspace:summary:{$memberId}";
    }

    /**
     * @return array<string, mixed>
     */
    private function compose(Member $member): array
    {
        $member->loadMissing(['cashAccount', 'fundAccount', 'parent', 'user']);

        $dependentsCount = $member->dependents()->count();
        $dependents = $dependentsCount > 0
            ? $member->dependents()->orderBy('name')->limit(3)->get()
            : collect();

        $cycles = app(ContributionCycleService::class);
        $delinquency = app(LoanDelinquencyService::class);
        $currency = InsightFormatter::currency();

        [$curMonth, $curYear] = $cycles->currentOpenPeriod();
        $periodLabel = $cycles->periodLabel($curMonth, $curYear);

        $cashBalance = $member->getCashBalance();
        $fundBalance = $member->getFundBalance();
        $monthly = (float) $member->monthly_contribution_amount;

        $postedThisPeriod = Contribution::query()
            ->where('member_id', $member->id)
            ->forPeriod($curMonth, $curYear)
            ->posted()
            ->exists();

        $activeLoan = Loan::query()
            ->where('member_id', $member->id)
            ->active()
            ->withCount([
                'installments as installments_paid_count' => fn ($query) => $query->where('status', 'paid'),
                'installments as installments_total_count',
                'installments as open_installments_count' => fn ($query) => $query->whereIn('status', ['pending', 'overdue']),
            ])
            ->latest('applied_at')
            ->first(['id', 'status']);

        $underLoanRepayment = (int) ($activeLoan?->open_installments_count ?? 0) > 0;
        $exempt = $member->isExemptFromContributions($curMonth, $curYear);
        $requiredCash = $underLoanRepayment || $exempt
            ? 0.0
            : $cycles->requiredCashForMemberPeriod($member, $curMonth, $curYear);
        $cashReady = $requiredCash <= 0.00001 || $cashBalance >= $requiredCash;

        $cycle = $this->resolveCycleChip(
            $postedThisPeriod,
            $exempt,
            $underLoanRepayment,
            $cashReady,
            $periodLabel,
        );

        $overdueInstallments = $delinquency->memberHasOverdueInstallments($member);
        $priorPeriodArrears = $this->hasPriorClosedPeriodContributionArrears($member, $cycles, $curMonth, $curYear);
        $arrearsVisible = $overdueInstallments || $priorPeriodArrears;

        $installmentsPaid = (int) ($activeLoan?->installments_paid_count ?? 0);
        $installmentsTotal = (int) ($activeLoan?->installments_total_count ?? 0);
        $repayPercent = $installmentsTotal > 0
            ? (int) round(($installmentsPaid / $installmentsTotal) * 100)
            : 0;

        $cashAccount = $member->cashAccount;
        $fundAccount = $member->fundAccount;

        $dependents = $dependents->values();

        return [
            'currency' => $currency,
            'balances' => [
                'cash' => [
                    'amount' => $cashBalance,
                    'negative' => $cashBalance < 0,
                    'url' => $cashAccount ? AccountResource::getUrl('view', ['record' => $cashAccount]) : null,
                ],
                'fund' => [
                    'amount' => $fundBalance,
                    'negative' => $fundBalance < 0,
                    'url' => $fundAccount ? AccountResource::getUrl('view', ['record' => $fundAccount]) : null,
                ],
            ],
            'cycle' => [
                'period_label' => $periodLabel,
                'label' => $cycle['label'],
                'tone' => $cycle['tone'],
                'url' => ContributionResource::listTabUrl('collect'),
            ],
            'arrears' => [
                'visible' => $arrearsVisible,
                'cta_label' => $overdueInstallments
                    ? __('Overdue installments')
                    : __('Contribution arrears'),
                'cta_url' => $overdueInstallments
                    ? LoanResource::overdueInstallmentsUrlForMember($member)
                    : ContributionResource::arrearsUrlForMember($member),
            ],
            'loan' => $activeLoan ? [
                'id' => $activeLoan->id,
                'status_label' => Loan::statusOptions()[$activeLoan->status] ?? $activeLoan->status,
                'installments_paid' => $installmentsPaid,
                'installments_total' => $installmentsTotal,
                'repay_percent' => $repayPercent,
                'url' => MemberResource::workspaceUrl($member, LoansRelationManager::class),
            ] : null,
            'household' => [
                'parent_name' => $member->parent?->name,
                'parent_url' => $member->parent
                    ? MemberResource::getUrl('view', ['record' => $member->parent])
                    : null,
                'dependents' => $dependents
                    ->take(3)
                    ->map(fn (Member $dependent): array => [
                        'name' => $dependent->name,
                        'url' => MemberResource::getUrl('view', ['record' => $dependent]),
                    ])
                    ->all(),
                'dependents_count' => $dependentsCount,
            ],
            'links' => [
                'ledger' => MemberResource::workspaceUrl($member, MemberTransactionsTabsRelationManager::class),
                'contributions' => ContributionResource::ledgerUrlForMember($member),
                'loans' => LoanResource::portfolioUrlForMember($member),
            ],
            'monthly_formatted' => InsightFormatter::money($monthly),
        ];
    }

    /**
     * @return array{label: string, tone: string}
     */
    private function resolveCycleChip(
        bool $postedThisPeriod,
        bool $exempt,
        bool $underLoanRepayment,
        bool $cashReady,
        string $periodLabel,
    ): array {
        if ($postedThisPeriod) {
            return [
                'label' => __('Posted · :period', ['period' => $periodLabel]),
                'tone' => 'success',
            ];
        }

        if ($underLoanRepayment) {
            return [
                'label' => __('Loan EMI · :period', ['period' => $periodLabel]),
                'tone' => 'violet',
            ];
        }

        if ($exempt) {
            return [
                'label' => __('Exempt · :period', ['period' => $periodLabel]),
                'tone' => 'gray',
            ];
        }

        if ($cashReady) {
            return [
                'label' => __('Ready · :period', ['period' => $periodLabel]),
                'tone' => 'success',
            ];
        }

        return [
            'label' => __('Need cash · :period', ['period' => $periodLabel]),
            'tone' => 'warning',
        ];
    }

    private function hasPriorClosedPeriodContributionArrears(
        Member $member,
        ContributionCycleService $cycles,
        int $openMonth,
        int $openYear,
    ): bool {
        $previous = Carbon::create($openYear, $openMonth, 1)->subMonthNoOverflow();
        $month = (int) $previous->month;
        $year = (int) $previous->year;

        if (! $cycles->memberIsLiableForContributionPeriod($member, $month, $year)) {
            return false;
        }

        if ($member->isExemptFromContributions($month, $year)) {
            return false;
        }

        return ! Contribution::query()
            ->where('member_id', $member->id)
            ->forPeriod($month, $year)
            ->posted()
            ->exists();
    }
}
