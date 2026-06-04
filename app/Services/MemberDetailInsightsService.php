<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\FundPostings\FundPostingResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\BusinessDay;
use App\Support\Insights\DualProgressTrendBuilder;
use App\Support\Insights\InsightFormatter;
use Carbon\Carbon;
use Illuminate\Support\Str;

final class MemberDetailInsightsService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(Member $member): array
    {
        $member->loadMissing(['cashAccount', 'fundAccount', 'parent', 'dependents', 'user']);
        $member = $member->fresh() ?? $member;

        $cycles = app(ContributionCycleService::class);
        $delinquency = app(LoanDelinquencyService::class);
        $loanService = app(LoanService::class);

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

        $exempt = $member->isExemptFromContributions();
        $canApply = $cycles->memberCanApplyContributionForPeriod($member, $curMonth, $curYear);
        $requiredCash = $cycles->requiredCashForMemberPeriod($member, $curMonth, $curYear);
        $cashReady = $cashBalance >= $requiredCash;

        $cycleStatus = $this->resolveCycleStatus(
            $member,
            $postedThisPeriod,
            $exempt,
            $canApply,
            $cashReady,
        );

        $arrears = $delinquency->memberArrearsSummary($member);

        $activeLoan = Loan::query()
            ->where('member_id', $member->id)
            ->active()
            ->with(['installments', 'guarantor'])
            ->latest('applied_at')
            ->first();

        $loanOutstanding = $activeLoan ? $activeLoan->getOutstandingBalance() : 0.0;
        $installmentsTotal = $activeLoan?->installments->count() ?? 0;
        $installmentsPaid = $activeLoan?->installments->where('status', 'paid')->count() ?? 0;
        $installmentsOverdue = $activeLoan?->installments->where('status', 'overdue')->count() ?? 0;
        $repayPercent = $installmentsTotal > 0
            ? (int) round(($installmentsPaid / $installmentsTotal) * 100)
            : 0;

        $contributionsPostedCount = (int) Contribution::query()
            ->where('member_id', $member->id)
            ->posted()
            ->count();

        $contributionsPostedTotal = (float) Contribution::query()
            ->where('member_id', $member->id)
            ->posted()
            ->sum('amount');

        $pendingPostings = (int) FundPosting::query()
            ->where('member_id', $member->id)
            ->where('status', 'pending')
            ->count();

        $dependentsCount = $member->dependents()->count();
        $eligibility = $loanService->checkEligibility($member);

        $cashKpi = InsightFormatter::moneyKpi($cashBalance);
        $fundKpi = InsightFormatter::moneyKpi($fundBalance);

        $hero = $this->buildHero($member, $arrears, $cycleStatus, $activeLoan, $installmentsOverdue);
        $kpis = $this->buildKpis(
            $member,
            $cashKpi,
            $fundKpi,
            $monthly,
            $cycleStatus,
            $loanOutstanding,
            $dependentsCount,
            $contributionsPostedCount,
            $pendingPostings,
        );

        $cashAccount = $member->cashAccount;
        $fundAccount = $member->fundAccount;
        $trend = DualProgressTrendBuilder::sixMonthMemberCollectionTrend($member, app(ContributionCycleService::class));
        $sparkline = $this->cashActivitySparkline($member);

        return [
            'member' => [
                'id' => $member->id,
                'name' => $member->name,
                'number' => $member->member_number,
                'status' => $member->status,
                'status_label' => Member::statusOptions()[$member->status] ?? $member->status,
                'joined_at' => $member->joined_at !== null
                    ? Carbon::parse((string) $member->joined_at)->format('d M Y')
                    : null,
                'tenure_months' => $member->joined_at
                    ? (int) Carbon::parse((string) $member->joined_at)->diffInMonths(BusinessDay::now())
                    : null,
                'is_parent' => $member->isParent(),
                'parent_name' => $member->parent?->name,
                'parent_url' => $member->parent
                    ? MemberResource::getUrl('edit', ['record' => $member->parent])
                    : null,
            ],
            'currency' => $currency,
            'hero' => $hero,
            'kpis' => $kpis,
            'steps' => $this->lifecycleSteps($member, $postedThisPeriod, $activeLoan, $arrears),
            'balances' => [
                'cash' => [
                    'display' => InsightFormatter::money($cashBalance),
                    'negative' => $cashBalance < 0,
                    'url' => $cashAccount ? AccountResource::getUrl('view', ['record' => $cashAccount]) : null,
                ],
                'fund' => [
                    'display' => InsightFormatter::money($fundBalance),
                    'negative' => $fundBalance < 0,
                    'url' => $fundAccount ? AccountResource::getUrl('view', ['record' => $fundAccount]) : null,
                ],
            ],
            'cycle' => [
                'period_label' => $periodLabel,
                'status_key' => $cycleStatus['key'],
                'status_label' => $cycleStatus['label'],
                'status_tone' => $cycleStatus['tone'],
                'required_cash' => InsightFormatter::money($requiredCash),
                'cash_ready' => $cashReady,
                'posted' => $postedThisPeriod,
                'exempt' => $exempt,
                'can_apply' => $canApply,
                'cycle_url' => ContributionResource::listTabUrl('collect'),
            ],
            'arrears' => [
                'visible' => $arrears['has_arrears'] || $arrears['is_delinquent'],
                'overdue_installments' => $arrears['overdue_installment_count'],
                'unpaid_periods' => $arrears['unpaid_contribution_periods'],
                ...$this->arrearsCta($member, $arrears),
            ],
            'loan' => $activeLoan ? [
                'id' => $activeLoan->id,
                'status' => $activeLoan->status,
                'status_label' => Loan::statusOptions()[$activeLoan->status] ?? $activeLoan->status,
                'outstanding' => InsightFormatter::money($loanOutstanding),
                'repay_percent' => $repayPercent,
                'installments_paid' => $installmentsPaid,
                'installments_total' => $installmentsTotal,
                'overdue_count' => $installmentsOverdue,
                'edit_url' => LoanResource::getUrl('edit', ['record' => $activeLoan]),
                'view_url' => LoanResource::getUrl('view', ['record' => $activeLoan]),
            ] : null,
            'eligibility' => [
                'eligible' => $eligibility['eligible'],
                'reason' => $eligibility['reason'] ?? null,
            ],
            'household' => [
                'dependents' => $member->dependents()
                    ->orderBy('name')
                    ->limit(5)
                    ->get()
                    ->map(fn (Member $dependent): array => [
                        'name' => $dependent->name,
                        'number' => $dependent->member_number,
                        'status' => Member::statusOptions()[$dependent->status] ?? $dependent->status,
                        'edit_url' => MemberResource::getUrl('edit', ['record' => $dependent]),
                    ])
                    ->all(),
                'dependents_count' => $dependentsCount,
            ],
            'fund_summary' => [
                'contributions_count' => $contributionsPostedCount,
                'contributions_total' => InsightFormatter::money($contributionsPostedTotal),
                'pending_postings' => $pendingPostings,
                'fund_minimum_pct' => $monthly > 0
                    ? min(100, (int) round(($fundBalance / $monthly) * 100))
                    : null,
            ],
            'trend' => $trend,
            'sparkline' => $sparkline,
            'sparkline_max' => max(1, max($sparkline)),
            'recent_contributions' => $this->recentContributions($member),
            'recent_activity' => $this->recentTransactions($member),
            'relation_summaries' => $this->relationSummaries(
                $member,
                $contributionsPostedCount,
                $pendingPostings,
                $activeLoan,
                $loanOutstanding,
                $dependentsCount,
            ),
            'quick_links' => [
                [
                    'label' => __('Contributions'),
                    'url' => ContributionResource::ledgerUrlForMember($member),
                    'icon' => 'heroicon-o-banknotes',
                ],
                [
                    'label' => __('Postings'),
                    'url' => FundPostingResource::indexUrlForMember($member),
                    'icon' => 'heroicon-o-inbox-arrow-down',
                ],
                [
                    'label' => __('Loans'),
                    'url' => LoanResource::portfolioUrlForMember($member),
                    'icon' => 'heroicon-o-currency-dollar',
                ],
            ],
        ];
    }

    /**
     * @param  array{has_arrears: bool, is_delinquent: bool, overdue_installment_count: int, unpaid_contribution_periods: list<string>}  $arrears
     * @param  array{key: string, label: string, tone: string}  $cycleStatus
     * @return array{tone: string, title: string, subtitle: string, cta_label: ?string, cta_url: ?string}
     */
    /**
     * @param  array{has_arrears: bool, is_delinquent: bool, overdue_installment_count: int, unpaid_contribution_periods: list<string>}  $arrears
     * @return array{cta_label: string, cta_url: string}
     */
    private function arrearsCta(Member $member, array $arrears): array
    {
        if (count($arrears['unpaid_contribution_periods']) > 0) {
            return [
                'cta_label' => __('Contribution arrears'),
                'cta_url' => ContributionResource::arrearsUrlForMember($member),
            ];
        }

        if ($arrears['overdue_installment_count'] > 0) {
            return [
                'cta_label' => __('Overdue installments'),
                'cta_url' => LoanResource::overdueInstallmentsUrlForMember($member),
            ];
        }

        return [
            'cta_label' => __('Delinquent members'),
            'cta_url' => MemberResource::listTabUrl('delinquent'),
        ];
    }

    private function buildHero(
        Member $member,
        array $arrears,
        array $cycleStatus,
        ?Loan $activeLoan,
        int $installmentsOverdue,
    ): array {
        if ($installmentsOverdue > 0) {
            return [
                'tone' => 'danger',
                'title' => __('Arrears need attention'),
                'subtitle' => trans_choice(':count overdue installment|:count overdue installments', $installmentsOverdue, ['count' => $installmentsOverdue]),
                'cta_label' => __('Overdue installments'),
                'cta_url' => LoanResource::overdueInstallmentsUrlForMember($member),
            ];
        }

        if ($arrears['is_delinquent']) {
            return [
                'tone' => 'danger',
                'title' => __('Member is delinquent'),
                'subtitle' => __('Restore active after arrears are cleared, or use force restore on the member record.'),
                'cta_label' => __('Member record'),
                'cta_url' => MemberResource::getUrl('edit', ['record' => $member]),
            ];
        }

        if ($arrears['has_arrears']) {
            return [
                'tone' => 'amber',
                'title' => __('Outstanding obligations'),
                'subtitle' => __('Review unpaid contributions or installments'),
                'cta_label' => __('Contribution arrears'),
                'cta_url' => ContributionResource::arrearsUrlForMember($member),
            ];
        }

        if ($cycleStatus['key'] === 'ready') {
            return [
                'tone' => 'success',
                'title' => __('Ready for :period', ['period' => $cycleStatus['period'] ?? '']),
                'subtitle' => __('Cash balance covers the open-cycle contribution'),
                'cta_label' => __('Cycle'),
                'cta_url' => ContributionResource::listTabUrl('collect'),
            ];
        }

        if ($cycleStatus['key'] === 'posted') {
            return [
                'tone' => 'success',
                'title' => __('Contribution posted'),
                'subtitle' => __(':period is recorded for this member', ['period' => $cycleStatus['period'] ?? '']),
                'cta_label' => null,
                'cta_url' => null,
            ];
        }

        if ($activeLoan !== null) {
            return [
                'tone' => 'sky',
                'title' => __('Active loan in progress'),
                'subtitle' => Loan::statusOptions()[$activeLoan->status] ?? $activeLoan->status,
                'cta_label' => __('Open loan'),
                'cta_url' => LoanResource::getUrl('edit', ['record' => $activeLoan]),
            ];
        }

        if (! in_array($member->status, ['active'], true)) {
            return [
                'tone' => 'amber',
                'title' => Member::statusOptions()[$member->status] ?? ucfirst($member->status),
                'subtitle' => __('Membership is not fully active'),
                'cta_label' => null,
                'cta_url' => null,
            ];
        }

        return [
            'tone' => 'success',
            'title' => __('Member in good standing'),
            'subtitle' => __('Balances and cycle status look healthy'),
            'cta_label' => null,
            'cta_url' => null,
        ];
    }

    /**
     * @param  array{display: string, full: string, is_negative: bool}  $cashKpi
     * @param  array{display: string, full: string, is_negative: bool}  $fundKpi
     * @param  array{key: string, label: string, tone: string, period?: string}  $cycleStatus
     * @return list<array<string, mixed>>
     */
    private function buildKpis(
        Member $member,
        array $cashKpi,
        array $fundKpi,
        float $monthly,
        array $cycleStatus,
        float $loanOutstanding,
        int $dependentsCount,
        int $contributionsPostedCount,
        int $pendingPostings,
    ): array {
        $loanKpi = InsightFormatter::moneyKpi($loanOutstanding);

        return [
            [
                'label' => __('Cash'),
                'value' => $cashKpi['display'],
                'sub' => $cashKpi['full'],
                'icon' => 'heroicon-o-wallet',
                'accent' => $cashKpi['is_negative'] ? 'rose' : 'emerald',
                'value_class' => $cashKpi['is_negative']
                    ? 'text-rose-600 dark:text-rose-400'
                    : 'text-emerald-600 dark:text-emerald-400',
            ],
            [
                'label' => __('Fund'),
                'value' => $fundKpi['display'],
                'sub' => $fundKpi['full'],
                'icon' => 'heroicon-o-building-library',
                'accent' => $fundKpi['is_negative'] ? 'rose' : 'indigo',
                'value_class' => $fundKpi['is_negative']
                    ? 'text-rose-600 dark:text-rose-400'
                    : 'text-indigo-600 dark:text-indigo-400',
            ],
            [
                'label' => __('Monthly'),
                'value' => InsightFormatter::compactAmount($monthly),
                'sub' => InsightFormatter::money($monthly),
                'icon' => 'heroicon-o-calendar',
                'accent' => 'sky',
            ],
            [
                'label' => __('Cycle'),
                'value' => $cycleStatus['short'] ?? '—',
                'sub' => $cycleStatus['label'],
                'icon' => 'heroicon-o-arrow-path',
                'accent' => $cycleStatus['tone'],
            ],
            [
                'label' => __('Loan due'),
                'value' => $loanOutstanding > 0 ? $loanKpi['display'] : '—',
                'sub' => $loanOutstanding > 0 ? $loanKpi['full'] : __('No active loan'),
                'icon' => 'heroicon-o-scale',
                'accent' => $loanOutstanding > 0 ? 'violet' : 'teal',
            ],
            [
                'label' => __('Posted'),
                'value' => (string) $contributionsPostedCount,
                'sub' => $pendingPostings > 0
                    ? trans_choice(':count posting pending|:count postings pending', $pendingPostings, ['count' => $pendingPostings])
                    : ($dependentsCount > 0
                        ? trans_choice(':count dependent|:count dependents', $dependentsCount, ['count' => $dependentsCount])
                        : __('Contributions')),
                'icon' => 'heroicon-o-check-badge',
                'accent' => $pendingPostings > 0 ? 'amber' : 'teal',
            ],
        ];
    }

    /**
     * @return array{key: string, label: string, tone: string, short: string, period?: string}
     */
    private function resolveCycleStatus(
        Member $member,
        bool $postedThisPeriod,
        bool $exempt,
        bool $canApply,
        bool $cashReady,
    ): array {
        $cycles = app(ContributionCycleService::class);
        [$curMonth, $curYear] = $cycles->currentOpenPeriod();
        $period = $cycles->periodLabel($curMonth, $curYear);

        if ($postedThisPeriod) {
            return [
                'key' => 'posted',
                'label' => __('Posted for :period', ['period' => $period]),
                'short' => __('Posted'),
                'tone' => 'emerald',
                'period' => $period,
            ];
        }

        if ($exempt) {
            return [
                'key' => 'exempt',
                'label' => __('Exempt while loan installments are pending'),
                'short' => __('Exempt'),
                'tone' => 'amber',
                'period' => $period,
            ];
        }

        if ($canApply) {
            return [
                'key' => $cashReady ? 'ready' : 'short',
                'label' => $cashReady
                    ? __('Ready · :period', ['period' => $period])
                    : __('Need cash · :period', ['period' => $period]),
                'short' => $cashReady ? __('Ready') : __('Short'),
                'tone' => $cashReady ? 'emerald' : 'rose',
                'period' => $period,
            ];
        }

        if ($member->status !== 'active' || (float) $member->monthly_contribution_amount <= 0) {
            return [
                'key' => 'na',
                'label' => __('Not due this cycle'),
                'short' => __('N/A'),
                'tone' => 'gray',
                'period' => $period,
            ];
        }

        return [
            'key' => 'waiting',
            'label' => __(':period not yet posted', ['period' => $period]),
            'short' => __('Open'),
            'tone' => 'sky',
            'period' => $period,
        ];
    }

    /**
     * @param  array{has_arrears: bool, is_delinquent: bool}  $arrears
     * @return list<array{key: string, label: string, state: string}>
     */
    private function lifecycleSteps(
        Member $member,
        bool $postedThisPeriod,
        ?Loan $activeLoan,
        array $arrears,
    ): array {
        $steps = [
            [
                'key' => 'joined',
                'label' => __('Joined'),
                'state' => $member->joined_at ? 'complete' : 'upcoming',
            ],
            [
                'key' => 'active',
                'label' => __('Active'),
                'state' => $member->status === 'active' ? 'complete' : ($member->status === 'delinquent' ? 'warning' : 'upcoming'),
            ],
            [
                'key' => 'cycle',
                'label' => __('Cycle'),
                'state' => $postedThisPeriod ? 'complete' : ($member->status === 'active' ? 'current' : 'upcoming'),
            ],
        ];

        if ($activeLoan !== null) {
            $steps[] = [
                'key' => 'loan',
                'label' => __('Loan'),
                'state' => 'current',
            ];
        }

        if ($arrears['has_arrears'] || $arrears['is_delinquent']) {
            $steps[] = [
                'key' => 'arrears',
                'label' => __('Arrears'),
                'state' => 'warning',
            ];
        }

        return $steps;
    }

    /**
     * @return list<int>
     */
    private function cashActivitySparkline(Member $member): array
    {
        $accountId = $member->cashAccount?->id;

        if ($accountId === null) {
            return array_fill(0, 8, 0);
        }

        $now = BusinessDay::now();
        $oldestDay = $now->copy()->subDays(7)->startOfDay();
        $dayCounts = [];

        Transaction::query()
            ->where('account_id', $accountId)
            ->whereBetween('transacted_at', [$oldestDay, $now->copy()->endOfDay()])
            ->get(['transacted_at'])
            ->each(function (Transaction $transaction) use (&$dayCounts): void {
                $transactedAt = $transaction->transacted_at;

                if ($transactedAt === null) {
                    return;
                }

                $key = Carbon::parse((string) $transactedAt)->startOfDay()->toDateString();
                $dayCounts[$key] = ($dayCounts[$key] ?? 0) + 1;
            });

        $points = [];

        for ($i = 7; $i >= 0; $i--) {
            $day = $now->copy()->subDays($i)->startOfDay()->toDateString();
            $points[] = $dayCounts[$day] ?? 0;
        }

        return $points;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentContributions(Member $member): array
    {
        return Contribution::query()
            ->where('member_id', $member->id)
            ->posted()
            ->orderByDesc('posted_at')
            ->limit(5)
            ->get()
            ->map(fn (Contribution $contribution): array => [
                'period' => $contribution->period !== null
                    ? Carbon::parse((string) $contribution->period)->format('M Y')
                    : '—',
                'amount' => InsightFormatter::money((float) $contribution->amount),
                'posted_at' => $contribution->posted_at?->format('d M'),
                'late' => (bool) $contribution->is_late,
            ])
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentTransactions(Member $member): array
    {
        $accountIds = $member->accounts()->pluck('id');

        if ($accountIds->isEmpty()) {
            return [];
        }

        return Transaction::query()
            ->whereIn('account_id', $accountIds)
            ->orderByDesc('transacted_at')
            ->limit(5)
            ->get()
            ->map(fn (Transaction $transaction): array => [
                'description' => Str::limit($transaction->description ?? '—', 40),
                'transacted_at' => $transaction->transacted_at?->format('d M, H:i'),
                'amount' => InsightFormatter::money((float) $transaction->amount),
                'signed_class' => $transaction->type === 'credit'
                    ? 'text-emerald-600 dark:text-emerald-400'
                    : 'text-rose-600 dark:text-rose-400',
                'type' => $transaction->type,
            ])
            ->all();
    }

    /**
     * @return list<array{key: string, label: string, value: string, hint: ?string, accent: string, icon: string, url: ?string}>
     */
    private function relationSummaries(
        Member $member,
        int $contributionsPostedCount,
        int $pendingPostings,
        ?Loan $activeLoan,
        float $loanOutstanding,
        int $dependentsCount,
    ): array {
        return [
            [
                'key' => 'accounts',
                'label' => __('Accounts'),
                'value' => InsightFormatter::money($member->getCashBalance()).' · '.__('Cash'),
                'hint' => InsightFormatter::money($member->getFundBalance()).' '.__('fund'),
                'accent' => 'indigo',
                'icon' => 'heroicon-o-rectangle-stack',
                'url' => $member->cashAccount
                    ? AccountResource::getUrl('view', ['record' => $member->cashAccount])
                    : null,
            ],
            [
                'key' => 'contributions',
                'label' => __('Contributions'),
                'value' => (string) $contributionsPostedCount.' '.__('posted'),
                'hint' => $pendingPostings > 0
                    ? trans_choice(':count posting pending|:count postings pending', $pendingPostings, ['count' => $pendingPostings])
                    : null,
                'accent' => 'emerald',
                'icon' => 'heroicon-o-banknotes',
                'url' => ContributionResource::ledgerUrlForMember($member),
            ],
            [
                'key' => 'loans',
                'label' => __('Loans'),
                'value' => $activeLoan
                    ? __('Active · :amount', ['amount' => InsightFormatter::money($loanOutstanding)])
                    : __('No active loan'),
                'hint' => $activeLoan
                    ? (Loan::statusOptions()[$activeLoan->status] ?? $activeLoan->status)
                    : null,
                'accent' => $activeLoan ? 'violet' : 'teal',
                'icon' => 'heroicon-o-currency-dollar',
                'url' => $activeLoan
                    ? LoanResource::getUrl('edit', ['record' => $activeLoan])
                    : LoanResource::portfolioUrlForMember($member),
            ],
            [
                'key' => 'household',
                'label' => __('Household'),
                'value' => $member->isParent()
                    ? trans_choice(':count dependent|:count dependents', $dependentsCount, ['count' => $dependentsCount])
                    : ($member->parent?->name ?? __('Independent')),
                'hint' => $member->isParent() ? __('Household head') : __('Linked to parent'),
                'accent' => 'sky',
                'icon' => 'heroicon-o-users',
                'url' => $member->parent
                    ? MemberResource::getUrl('edit', ['record' => $member->parent])
                    : null,
            ],
        ];
    }
}
