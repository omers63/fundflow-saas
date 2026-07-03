<?php

declare(strict_types=1);

namespace App\Services;

use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Filament\Tenant\Resources\CashOutRequests\CashOutRequestResource;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Filament\Tenant\Resources\FundPostings\FundPostingResource;
use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanRepayment;
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
        $underLoanRepayment = $member->hasActiveLoanRepaymentObligation();
        $requiredCash = $underLoanRepayment
            ? 0.0
            : $cycles->requiredCashForMemberPeriod($member, $curMonth, $curYear);
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
        $lifetimeDisbursed = $this->lifetimeDisbursedTotal($member);
        $disbursedLoanCount = $this->disbursedLoanCount($member);
        $lifetimeRepaid = $this->lifetimeRepaidTotal($member);
        $totalFundInflow = $contributionsPostedTotal + $lifetimeRepaid + $cashBalance;

        $cashKpi = InsightFormatter::moneyKpi($cashBalance);
        $fundKpi = InsightFormatter::moneyKpi($fundBalance);

        $hero = $this->buildHero($member, $arrears, $cycleStatus, $activeLoan, $installmentsOverdue);
        $steps = $this->lifecycleSteps($member, $postedThisPeriod, $activeLoan, $arrears);
        $kpis = $this->buildKpis(
            $member,
            $cashKpi,
            $fundKpi,
            $monthly,
            $cycleStatus,
            $loanOutstanding,
            $lifetimeDisbursed,
            $disbursedLoanCount,
            $contributionsPostedTotal,
            $lifetimeRepaid,
            $totalFundInflow,
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
                    ? MemberResource::getUrl('view', ['record' => $member->parent])
                    : null,
            ],
            'currency' => $currency,
            'hero' => $hero,
            'snapshot' => $this->buildSnapshot(
                $hero,
                $cycleStatus,
                $monthly,
                $activeLoan,
                $installmentsPaid,
                $installmentsTotal,
                $repayPercent,
                $monthly > 0 ? min(100, (int) round(($fundBalance / $monthly) * 100)) : null,
            ),
            'metrics' => $this->buildMetrics(
                $member,
                $lifetimeDisbursed,
                $disbursedLoanCount,
                $contributionsPostedTotal,
                $lifetimeRepaid,
                $contributionsPostedCount,
                $pendingPostings,
                $dependentsCount,
            ),
            'kpis' => $kpis,
            'steps' => $steps,
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
                'status_key' => $cycleStatus['key'],
                'status_label' => $cycleStatus['label'],
                'status_tone' => $cycleStatus['tone'],
                'under_loan_repayment' => $underLoanRepayment,
                'loan_repayment_message' => $underLoanRepayment
                    ? __('Under loan repayment')
                    : null,
                'required_cash' => $requiredCash,
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
                'outstanding' => $loanOutstanding,
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
                        'edit_url' => MemberResource::getUrl('view', ['record' => $dependent]),
                    ])
                    ->all(),
                'dependents_count' => $dependentsCount,
            ],
            'fund_summary' => [
                'contributions_count' => $contributionsPostedCount,
                'contributions_total' => $contributionsPostedTotal,
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
                    'label' => __('Cash outs'),
                    'url' => CashOutRequestResource::indexUrlForMember($member),
                    'icon' => 'heroicon-o-arrow-up-tray',
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
     * @param  array{tone: string, title: string, subtitle: string, cta_label: ?string, cta_url: ?string}  $hero
     * @param  array{key: string, label: string, tone: string, short: string, period?: string}  $cycleStatus
     * @return array<string, mixed>
     */
    private function buildSnapshot(
        array $hero,
        array $cycleStatus,
        float $monthly,
        ?Loan $activeLoan,
        int $installmentsPaid,
        int $installmentsTotal,
        int $repayPercent,
        ?int $fundMinimumPct,
    ): array {
        return [
            'status_tone' => $hero['tone'],
            'status_title' => $hero['title'],
            'status_subtitle' => $hero['subtitle'],
            'status_cta_label' => $hero['cta_label'],
            'status_cta_url' => $hero['cta_url'],
            'monthly_formatted' => InsightFormatter::money($monthly),
            'cycle_summary' => $cycleStatus['short'].' · '.($cycleStatus['period'] ?? ''),
            'fund_minimum_pct' => $fundMinimumPct,
            'installments_paid' => $installmentsPaid,
            'installments_total' => $installmentsTotal,
            'repay_percent' => $repayPercent,
            'loan_outstanding' => $activeLoan ? $activeLoan->getOutstandingBalance() : null,
        ];
    }

    /**
     * @return list<array{label: string, value: string, url: ?string}>
     */
    private function buildMetrics(
        Member $member,
        float $lifetimeDisbursed,
        int $_disbursedLoanCount,
        float $lifetimeContributions,
        float $lifetimeRepaid,
        int $contributionsPostedCount,
        int $pendingPostings,
        int $dependentsCount,
    ): array {
        $metrics = [];

        if (abs($lifetimeContributions) > 0.00001) {
            $metrics[] = [
                'label' => __('Contributions'),
                'value' => InsightFormatter::compactAmount($lifetimeContributions),
                'url' => ContributionResource::ledgerUrlForMember($member),
            ];
        }

        if (abs($lifetimeRepaid) > 0.00001) {
            $metrics[] = [
                'label' => __('Repaid'),
                'value' => InsightFormatter::compactAmount($lifetimeRepaid),
                'url' => LoanResource::portfolioUrlForMember($member),
            ];
        }

        if ($lifetimeDisbursed > 0) {
            $metrics[] = [
                'label' => __('Disbursed'),
                'value' => InsightFormatter::compactAmount($lifetimeDisbursed),
                'url' => LoanResource::portfolioUrlForMember($member),
            ];
        }

        if ($contributionsPostedCount > 0 || $pendingPostings > 0) {
            $metrics[] = [
                'label' => __('Posted'),
                'value' => $pendingPostings > 0
                    ? (string) $contributionsPostedCount.' · '.trans_choice(':count pending|:count pending', $pendingPostings, ['count' => $pendingPostings])
                    : (string) $contributionsPostedCount,
                'url' => ContributionResource::ledgerUrlForMember($member),
            ];
        }

        if ($dependentsCount > 0) {
            $metrics[] = [
                'label' => __('Household'),
                'value' => trans_choice(':count dependent|:count dependents', $dependentsCount, ['count' => $dependentsCount]),
                'url' => MemberResource::getUrl('view', ['record' => $member]),
            ];
        }

        return array_slice($metrics, 0, 5);
    }

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
                'title' => __('Active with arrears'),
                'subtitle' => __('Status remains active. Clear obligations to restore portal access.'),
                'cta_label' => __('Arrears'),
                'cta_url' => MemberResource::listTabUrl('delinquent'),
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
        float $lifetimeDisbursed,
        int $disbursedLoanCount,
        float $lifetimeContributions,
        float $lifetimeRepaid,
        float $totalFundInflow,
        int $dependentsCount,
        int $contributionsPostedCount,
        int $pendingPostings,
    ): array {
        $currency = InsightFormatter::currency();
        $loanKpi = InsightFormatter::moneyKpi($loanOutstanding);
        $lifetimeDisbursedKpi = InsightFormatter::moneyKpi($lifetimeDisbursed);
        $lifetimeContributionsKpi = InsightFormatter::moneyKpi($lifetimeContributions);
        $lifetimeRepaidKpi = InsightFormatter::moneyKpi($lifetimeRepaid);
        $totalFundInflowKpi = InsightFormatter::moneyKpi($totalFundInflow);

        return [
            [
                'label' => __('Cash'),
                'value' => $this->signedDisplayFromKpi($cashKpi),
                'sub' => $cashKpi['full'],
                'currency' => $currency,
                'icon' => 'heroicon-o-wallet',
                'accent' => $cashKpi['is_negative'] ? 'rose' : 'emerald',
                'value_class' => $cashKpi['is_negative']
                    ? 'text-rose-600 dark:text-rose-400'
                    : 'text-emerald-600 dark:text-emerald-400',
            ],
            [
                'label' => __('Fund'),
                'value' => $this->signedDisplayFromKpi($fundKpi),
                'sub' => $fundKpi['full'],
                'currency' => $currency,
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
                'currency' => $currency,
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
                'currency' => $currency,
                'icon' => 'heroicon-o-scale',
                'accent' => $loanOutstanding > 0 ? 'violet' : 'teal',
            ],
            [
                'key' => 'lifetime_disbursed',
                'label' => __('Lifetime disbursed'),
                'value' => $lifetimeDisbursed > 0 ? $lifetimeDisbursedKpi['display'] : '—',
                'sub' => $lifetimeDisbursed > 0
                    ? trans_choice(':count loan disbursed|:count loans disbursed', $disbursedLoanCount, ['count' => $disbursedLoanCount])
                    : __('No loans disbursed'),
                'currency' => $currency,
                'icon' => 'heroicon-o-banknotes',
                'accent' => $lifetimeDisbursed > 0 ? 'violet' : 'teal',
                'url' => LoanResource::portfolioUrlForMember($member),
            ],
            [
                'key' => 'lifetime_contributions',
                'label' => __('Lifetime contributions'),
                'value' => abs($lifetimeContributions) > 0.00001 ? $this->signedCompactAmount($lifetimeContributions) : '—',
                'sub' => abs($lifetimeContributions) > 0.00001 ? $lifetimeContributionsKpi['full'] : __('No contributions'),
                'currency' => $currency,
                'icon' => 'heroicon-o-banknotes',
                'accent' => $lifetimeContributions < 0 ? 'rose' : (abs($lifetimeContributions) > 0.00001 ? 'emerald' : 'teal'),
                'value_class' => $lifetimeContributions < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-900 dark:text-white',
                'url' => ContributionResource::ledgerUrlForMember($member),
            ],
            [
                'key' => 'lifetime_repaid',
                'label' => __('Lifetime repaid'),
                'value' => abs($lifetimeRepaid) > 0.00001 ? $this->signedCompactAmount($lifetimeRepaid) : '—',
                'sub' => abs($lifetimeRepaid) > 0.00001 ? $lifetimeRepaidKpi['full'] : __('No repayments'),
                'currency' => $currency,
                'icon' => 'heroicon-o-arrow-uturn-left',
                'accent' => $lifetimeRepaid < 0 ? 'rose' : (abs($lifetimeRepaid) > 0.00001 ? 'sky' : 'teal'),
                'value_class' => $lifetimeRepaid < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-900 dark:text-white',
                'url' => LoanResource::portfolioUrlForMember($member),
            ],
            [
                'key' => 'total_fund_inflow',
                'label' => __('Total fund inflow'),
                'value' => abs($totalFundInflow) > 0.00001 ? $this->signedCompactAmount($totalFundInflow) : '—',
                'sub' => abs($totalFundInflow) > 0.00001 ? $totalFundInflowKpi['full'] : __('No inflow yet'),
                'currency' => $currency,
                'icon' => 'heroicon-o-arrow-trending-up',
                'accent' => $totalFundInflow < 0 ? 'rose' : (abs($totalFundInflow) > 0.00001 ? 'indigo' : 'teal'),
                'value_class' => $totalFundInflow < 0 ? 'text-rose-600 dark:text-rose-400' : 'text-gray-900 dark:text-white',
                'url' => ContributionResource::ledgerUrlForMember($member),
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

    private function lifetimeDisbursedTotal(Member $member): float
    {
        return (float) Loan::query()
            ->where('member_id', $member->id)
            ->where('amount_disbursed', '>', 0)
            ->sum('amount_disbursed');
    }

    private function disbursedLoanCount(Member $member): int
    {
        return (int) Loan::query()
            ->where('member_id', $member->id)
            ->where('amount_disbursed', '>', 0)
            ->count();
    }

    private function lifetimeRepaidTotal(Member $member): float
    {
        return (float) LoanRepayment::query()
            ->whereHas('loan', fn ($query) => $query->where('member_id', $member->id))
            ->sum('amount');
    }

    private function signedCompactAmount(float $amount): string
    {
        $display = InsightFormatter::compactAmount($amount);

        return $amount < 0 ? '-'.$display : $display;
    }

    /**
     * @param  array{display: string, full: string, is_negative: bool}  $kpi
     */
    private function signedDisplayFromKpi(array $kpi): string
    {
        return $kpi['is_negative'] ? '-'.$kpi['display'] : $kpi['display'];
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

        if ($member->hasActiveLoanRepaymentObligation()) {
            return [
                'key' => 'loan_repayment',
                'label' => __('Under loan repayment'),
                'short' => __('Loan EMI'),
                'tone' => 'violet',
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
                'state' => $member->status === 'active' && ! $arrears['is_delinquent'] ? 'complete' : ($arrears['is_delinquent'] ? 'warning' : 'upcoming'),
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

        return array_map(
            fn (array $step): array => [
                ...$step,
                'description' => $this->lifecycleStepDescription($step, $member, $postedThisPeriod, $activeLoan, $arrears),
            ],
            $steps,
        );
    }

    /**
     * @param  array{key: string, label: string, state: string}  $step
     * @param  array{has_arrears: bool, is_delinquent: bool}  $arrears
     */
    private function lifecycleStepDescription(
        array $step,
        Member $member,
        bool $postedThisPeriod,
        ?Loan $activeLoan,
        array $arrears,
    ): ?string {
        return match ($step['key'] ?? '') {
            'joined' => $member->joined_at !== null
            ? __('Joined :date', ['date' => Carbon::parse((string) $member->joined_at)->format('d M Y')])
            : null,
            'active' => match (true) {
                $member->status === 'active' && $arrears['is_delinquent'] => __('Active with arrears — clear obligations to restore portal access'),
                $member->status === 'active' => __('Membership is active'),
                default => Member::statusOptions()[$member->status] ?? ucfirst($member->status),
            },
            'cycle' => $postedThisPeriod
            ? __('Contribution posted for the open cycle')
            : __('Open-cycle contribution is not yet posted'),
            'loan' => $activeLoan !== null
            ? __(':status — repayment in progress', ['status' => Loan::statusOptions()[$activeLoan->status] ?? $activeLoan->status])
            : null,
            'arrears' => $arrears['is_delinquent']
            ? __('Arrears breach — review obligations while status remains active')
            : __('Outstanding contributions or installments need attention'),
            default => null,
        };
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
                'amount' => (float) $contribution->amount,
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
                'description' => Str::limit($transaction->displayDescription(), 40),
                'transacted_at' => $transaction->transacted_at?->format('d M, H:i'),
                'amount' => (float) $transaction->amount,
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
                'value_amount' => $member->getCashBalance(),
                'hint' => InsightFormatter::money($member->getFundBalance()).' '.__('fund'),
                'hint_amount' => $member->getFundBalance(),
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
                'value_amount' => $activeLoan ? $loanOutstanding : null,
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
                    ? MemberResource::getUrl('view', ['record' => $member->parent])
                    : null,
            ],
        ];
    }
}
