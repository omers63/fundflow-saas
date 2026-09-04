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
use App\Models\Tenant\LoanRepayment;
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
        // Always re-query account balances — loadMissing keeps stale in-memory relations after postings.
        $member->unsetRelation('cashAccount');
        $member->unsetRelation('fundAccount');
        $member->load(['cashAccount', 'fundAccount', 'parent', 'user']);

        $dependents = $member->dependents()->orderBy('name')->get();
        $dependentsCount = $dependents->count();

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

        $lifetimePosted = Contribution::query()
            ->where('member_id', $member->id)
            ->posted()
            ->toBase()
            ->selectRaw('count(*) as posted_count, coalesce(sum(amount), 0) as posted_total')
            ->first();

        $postedCount = (int) ($lifetimePosted->posted_count ?? 0);
        $postedTotal = (float) ($lifetimePosted->posted_total ?? 0);

        // Same definitions as MemberPortalInsightsService (total loans / value / repayments / collection).
        $loanPortfolio = Loan::query()
            ->where('member_id', $member->id)
            ->toBase()
            ->selectRaw('count(*) as loans_count')
            ->selectRaw('coalesce(sum(coalesce(amount_approved, amount_requested, amount, 0)), 0) as loans_value')
            ->first();

        $loansCount = (int) ($loanPortfolio->loans_count ?? 0);
        $loansValue = (float) ($loanPortfolio->loans_value ?? 0);
        $repaymentsTotal = (float) LoanRepayment::query()
            ->whereIn(
                'loan_id',
                Loan::query()->where('member_id', $member->id)->select('id'),
            )
            ->sum('amount');
        $collectionTotal = $postedTotal + $repaymentsTotal;

        $activeLoan = Loan::query()
            ->where('member_id', $member->id)
            ->active()
            ->withCount([
                'installments as installments_paid_count' => fn ($query) => $query->where('status', 'paid'),
                'installments as installments_total_count',
                'installments as open_installments_count' => fn ($query) => $query->whereIn('status', ['pending', 'overdue']),
            ])
            ->latest('applied_at')
            ->first(['id', 'status', 'master_portion', 'repaid_to_master']);

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
        $loanOutstanding = $activeLoan ? $activeLoan->getOutstandingBalance() : 0.0;

        $cashAccount = $member->cashAccount;
        $fundAccount = $member->fundAccount;

        $statusKey = (string) $member->status;
        $statusLabel = Member::statusOptions()[$statusKey] ?? ucfirst($statusKey);

        return [
            'currency' => $currency,
            'member' => [
                'status' => $statusKey,
                'status_label' => $statusLabel,
                'member_number' => $member->member_number,
            ],
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
            'contributions' => [
                'posted_count' => $postedCount,
                'posted_total' => $postedTotal,
                'posted_total_formatted' => InsightFormatter::money($postedTotal),
                'hint' => $postedCount > 0
                    ? __(':count posted · :amount', [
                        'count' => number_format($postedCount),
                        'amount' => InsightFormatter::money($postedTotal),
                    ])
                    : __('No posted contributions yet'),
            ],
            'totals' => [
                'loans_count' => $loansCount,
                'loans_value' => $loansValue,
                'loans_value_formatted' => InsightFormatter::money($loansValue),
                'repayments' => $repaymentsTotal,
                'repayments_formatted' => InsightFormatter::money($repaymentsTotal),
                'collection' => $collectionTotal,
                'collection_formatted' => InsightFormatter::money($collectionTotal),
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
                'outstanding' => $loanOutstanding,
                'outstanding_formatted' => InsightFormatter::money($loanOutstanding),
                'url' => MemberResource::workspaceUrl($member, LoansRelationManager::class),
            ] : null,
            'household' => [
                'parent_name' => $member->parent?->name,
                'parent_url' => $member->parent
                    ? MemberResource::getUrl('view', ['record' => $member->parent])
                    : null,
                'dependents' => $dependents
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
            'monthly' => $monthly,
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
