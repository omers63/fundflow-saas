<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\ContributionCycleService;
use App\Support\InstallmentCollectionStatus;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Queries and apply helpers for the admin EMI collection workspace (parallel to contribution "To collect").
 */
class LoanEmiCollectionCatalogService
{
    public function __construct(
        protected ContributionCycleService $cycles,
        protected LoanInstallmentCollectionService $installmentCollection,
    ) {}

    /**
     * @var array<string, Collection<int, LoanInstallment>>
     */
    private array $collectableInstallmentsCache = [];

    /**
     * @return array{0: int, 1: int}
     */
    public function currentOpenPeriod(): array
    {
        return $this->cycles->currentOpenPeriod();
    }

    public function periodLabel(int $month, int $year): string
    {
        return $this->cycles->periodLabel($month, $year);
    }

    public function membersWithPendingEmisQuery(int $month, int $year): Builder
    {
        [$start, $end] = $this->cycles->cycleDueDateBounds($month, $year);

        return Member::query()
            ->active()
            ->whereHas('loans', function (Builder $loan) use ($start, $end): void {
                $loan->whereIn('status', ['active', 'transferred'])
                    ->whereHas('installments', function (Builder $installment) use ($start, $end): void {
                        $installment
                            ->whereIn('status', ['pending', 'overdue'])
                            ->where(function (Builder $query): void {
                                $query->whereNull('collection_status')
                                    ->orWhereIn('collection_status', InstallmentCollectionStatus::openCollectionStates());
                            })
                            ->whereBetween('due_date', [$start, $end]);
                    });
            })
            ->with(['cashAccount', 'parent']);
    }

    /**
     * Members with unpaid EMIs assigned to the labelled collection cycle (due date in cycle window).
     */
    public function membersWithCollectableEmisQuery(int $month, int $year): Builder
    {
        return $this->membersWithPendingEmisQuery($month, $year);
    }

    public function primaryCollectableLoanIdForMember(Member $member, int $month, int $year): ?int
    {
        $installment = $this->collectableInstallmentsForMember($member, $month, $year)->first();

        return $installment?->loan_id;
    }

    public function primaryCollectableLoanForMember(Member $member, int $month, int $year): ?Loan
    {
        return $this->collectableLoansForMember($member, $month, $year)->first();
    }

    /**
     * @return Collection<int, Loan>
     */
    public function collectableLoansForMember(Member $member, int $month, int $year): Collection
    {
        return $this->collectableInstallmentsForMember($member, $month, $year)
            ->loadMissing('loan')
            ->map(fn (LoanInstallment $installment): ?Loan => $installment->loan)
            ->filter()
            ->unique('id')
            ->values();
    }

    public function outstandingLoanBalanceForMember(Member $member, int $month, int $year): float
    {
        return round(
            $this->collectableLoansForMember($member, $month, $year)
                ->sum(fn (Loan $loan): float => $loan->getOutstandingBalance()),
            2,
        );
    }

    public function pendingMemberCount(int $month, int $year): int
    {
        return $this->membersWithCollectableEmisQuery($month, $year)->count();
    }

    public function collectedInstallmentCount(int $month, int $year): int
    {
        return $this->collectedInstallmentsQuery($month, $year)->count();
    }

    public function collectedInstallmentsCashTotal(int $month, int $year): float
    {
        return round(
            $this->collectedInstallmentsQuery($month, $year)
                ->get()
                ->sum(fn (LoanInstallment $installment): float => $installment->collectedCashAmount()),
            2,
        );
    }

    /**
     * @return Collection<int, LoanInstallment>
     */
    public function collectableInstallmentsForMember(Member $member, int $month, int $year): Collection
    {
        return $this->collectableInstallmentsForMemberInPeriod($member, $month, $year);
    }

    public function pendingInstallmentCountForMember(Member $member, int $month, int $year): int
    {
        return $this->collectableInstallmentsForMember($member, $month, $year)->count();
    }

    public function requiredCashForMember(Member $member, int $month, int $year): float
    {
        $total = 0.0;

        foreach ($this->collectableInstallmentsForMember($member, $month, $year) as $installment) {
            $total += $this->installmentCollection->requiredCashForInstallment($installment);
        }

        return $total;
    }

    public function memberHasSufficientCash(Member $member, int $month, int $year): bool
    {
        $required = $this->requiredCashForMember($member, $month, $year);

        if ($required <= 0.00001) {
            return true;
        }

        return $member->getCashBalance() >= $required - 0.00001;
    }

    /**
     * Collect open-period and arrears EMIs from member cash (same engine as deposit/contribution flows).
     *
     * @return 'collected'|'partial'|'no_cash'|'none'
     */
    public function applyForMember(
        Member $member,
        int $month,
        int $year,
        bool $collectOldestArrearsFirst = false,
    ): string {
        $results = [
            'applied' => collect(),
            'insufficient' => collect(),
            'skipped' => collect(),
        ];

        return $this->applyForMemberForPeriod($member, $month, $year, $results, $collectOldestArrearsFirst);
    }

    /**
     * @return Collection<int, LoanInstallment>
     */
    public function collectableInstallmentsForMemberInPeriod(Member $member, int $month, int $year): Collection
    {
        $cacheKey = $member->id.'|'.$year.'-'.$month;

        if (array_key_exists($cacheKey, $this->collectableInstallmentsCache)) {
            return $this->collectableInstallmentsCache[$cacheKey];
        }

        [$start, $end] = $this->cycles->cycleDueDateBounds($month, $year);

        return $this->collectableInstallmentsCache[$cacheKey] = LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->where(function (Builder $query): void {
                $query->whereNull('collection_status')
                    ->orWhereIn('collection_status', InstallmentCollectionStatus::openCollectionStates());
            })
            ->whereHas('loan', function (Builder $loan) use ($member): void {
                $loan->whereIn('status', ['active', 'transferred'])
                    ->where('member_id', $member->id);
            })
            ->whereBetween('due_date', [$start, $end])
            ->with('loan')
            ->orderBy('due_date')
            ->get()
            ->filter(function (LoanInstallment $installment) use ($member): bool {
                if ($installment->due_date === null) {
                    return false;
                }

                [$cycleMonth, $cycleYear] = $this->cycles->cyclePeriodForDueDate($installment->due_date);

                return ! Contribution::blocksLoanRepaymentForMemberPeriod($member, $cycleMonth, $cycleYear);
            })
            ->values();
    }

    public function pendingInstallmentCountForMemberInPeriod(Member $member, int $month, int $year): int
    {
        return $this->collectableInstallmentsForMemberInPeriod($member, $month, $year)->count();
    }

    /**
     * All installments whose due date falls in the labelled cycle window (any status).
     *
     * @return Collection<int, LoanInstallment>
     */
    public function installmentsDueInPeriodForMember(Member $member, int $month, int $year): Collection
    {
        [$start, $end] = $this->cycles->cycleDueDateBounds($month, $year);

        return LoanInstallment::query()
            ->whereHas('loan', function (Builder $loan) use ($member): void {
                $loan->whereIn('status', ['active', 'transferred'])
                    ->where('member_id', $member->id);
            })
            ->whereBetween('due_date', [$start, $end])
            ->orderBy('due_date')
            ->get();
    }

    /**
     * Whether any installment due in this labelled cycle is still unpaid.
     */
    public function hasUnpaidInstallmentDueInPeriod(Member $member, int $month, int $year): bool
    {
        return $this->pendingInstallmentCountForMemberInPeriod($member, $month, $year) > 0;
    }

    /**
     * Whether the labelled cycle had an EMI that is already fully paid.
     */
    public function hasPaidInstallmentDueInPeriod(Member $member, int $month, int $year): bool
    {
        return $this->installmentsDueInPeriodForMember($member, $month, $year)
            ->contains(fn (LoanInstallment $installment): bool => $installment->status === 'paid');
    }

    /**
     * Collect EMIs due in one cycle period from member cash (manual batch / cycle run).
     *
     * When {@see $collectOldestArrearsFirst} is true, available cash clears unpaid EMIs
     * oldest-first through the selected period (inclusive).
     *
     * @param  array{applied: Collection, insufficient: Collection, skipped: Collection}  $results
     * @return 'collected'|'partial'|'no_cash'|'none'
     */
    public function applyForMemberForPeriod(
        Member $member,
        int $month,
        int $year,
        array &$results = [],
        bool $collectOldestArrearsFirst = false,
    ): string {
        $this->forgetCollectableInstallmentsCacheForMember($member);

        $before = $collectOldestArrearsFirst
            ? $this->pendingInstallmentCountForMemberThroughPeriod($member, $month, $year)
            : $this->pendingInstallmentCountForMemberInPeriod($member, $month, $year);

        if ($before === 0) {
            $results['skipped'][] = $member;

            return 'none';
        }

        $this->installmentCollection->onMemberCashIncreasedForPeriod(
            $member->fresh() ?? $member,
            $month,
            $year,
            throughSelectedPeriod: $collectOldestArrearsFirst,
        );

        $this->forgetCollectableInstallmentsCacheForMember($member);

        $fresh = $member->fresh() ?? $member;
        $after = $collectOldestArrearsFirst
            ? $this->pendingInstallmentCountForMemberThroughPeriod($fresh, $month, $year)
            : $this->pendingInstallmentCountForMemberInPeriod($fresh, $month, $year);
        $settled = $before - $after;

        if ($settled === 0) {
            $results['insufficient'][] = [
                'member' => $member,
                'balance' => $fresh->getCashBalance(),
                'required' => $collectOldestArrearsFirst
                    ? $this->requiredCashForMemberThroughPeriod($member, $month, $year)
                    : $this->requiredCashForMemberInPeriod($member, $month, $year),
            ];

            return 'no_cash';
        }

        if ($after > 0) {
            $results['applied'][] = $member;

            return 'partial';
        }

        $results['applied'][] = $member;

        return 'collected';
    }

    private function forgetCollectableInstallmentsCacheForMember(Member $member): void
    {
        $prefix = $member->id.'|';

        foreach (array_keys($this->collectableInstallmentsCache) as $key) {
            if (str_starts_with((string) $key, $prefix)) {
                unset($this->collectableInstallmentsCache[$key]);
            }
        }
    }

    public function pendingInstallmentCountForMemberThroughPeriod(Member $member, int $month, int $year): int
    {
        return $this->collectableInstallmentsForMemberThroughPeriod($member, $month, $year)->count();
    }

    /**
     * Unpaid EMIs with due date on or before the selected cycle’s due end (oldest → selected).
     *
     * @return Collection<int, LoanInstallment>
     */
    public function collectableInstallmentsForMemberThroughPeriod(Member $member, int $month, int $year): Collection
    {
        $cacheKey = $member->id.'|through|'.$year.'-'.$month;

        if (array_key_exists($cacheKey, $this->collectableInstallmentsCache)) {
            return $this->collectableInstallmentsCache[$cacheKey];
        }

        $end = $this->cycles->cycleDueEndAt($month, $year)->toDateString();

        return $this->collectableInstallmentsCache[$cacheKey] = LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->where(function (Builder $query): void {
                $query->whereNull('collection_status')
                    ->orWhereIn('collection_status', InstallmentCollectionStatus::openCollectionStates());
            })
            ->whereHas('loan', function (Builder $loan) use ($member): void {
                $loan->whereIn('status', ['active', 'transferred'])
                    ->where('member_id', $member->id);
            })
            ->whereDate('due_date', '<=', $end)
            ->with('loan')
            ->orderBy('due_date')
            ->get()
            ->filter(function (LoanInstallment $installment) use ($member): bool {
                if ($installment->due_date === null) {
                    return false;
                }

                [$cycleMonth, $cycleYear] = $this->cycles->cyclePeriodForDueDate($installment->due_date);

                return ! Contribution::blocksLoanRepaymentForMemberPeriod($member, $cycleMonth, $cycleYear);
            })
            ->values();
    }

    public function requiredCashForMemberThroughPeriod(Member $member, int $month, int $year): float
    {
        $total = 0.0;

        foreach ($this->collectableInstallmentsForMemberThroughPeriod($member, $month, $year) as $installment) {
            $total += $this->installmentCollection->requiredCashForInstallment($installment);
        }

        return $total;
    }

    public function requiredCashForMemberInPeriod(Member $member, int $month, int $year): float
    {
        $total = 0.0;

        foreach ($this->collectableInstallmentsForMemberInPeriod($member, $month, $year) as $installment) {
            $total += $this->installmentCollection->requiredCashForInstallment($installment);
        }

        return $total;
    }

    /**
     * @param  array{applied: Collection, insufficient: Collection, skipped: Collection}  $results
     */
    public function applyHouseholdInstallmentsForPeriod(
        Member $parent,
        Collection $dependents,
        int $month,
        int $year,
        array &$results,
        bool $collectOldestArrearsFirst = false,
    ): void {
        $parent = $parent->fresh() ?? $parent;
        $parent->unsetRelation('accounts');

        if ($dependents->isNotEmpty() && $parent->getCashBalance() >= 0.00001) {
            $this->cycles->applyDependentAllocationForParentForPeriod($parent, $month, $year);
        }

        $this->applyForMemberForPeriod($parent, $month, $year, $results, $collectOldestArrearsFirst);

        foreach ($dependents as $dependent) {
            $this->applyForMemberForPeriod(
                $dependent->fresh() ?? $dependent,
                $month,
                $year,
                $results,
                $collectOldestArrearsFirst,
            );
        }
    }

    /**
     * @return array{applied: Collection<int, Member>, insufficient: Collection<int, array{member: Member, balance: float, required: float}>, skipped: Collection<int, Member>}
     */
    public function applyInstallmentsForPeriod(
        int $month,
        int $year,
        bool $collectOldestArrearsFirst = false,
    ): array {
        $results = [
            'applied' => collect(),
            'insufficient' => collect(),
            'skipped' => collect(),
        ];

        $householdMemberIds = [];

        Member::query()
            ->active()
            ->whereNull('parent_member_id')
            ->whereHas('dependents', fn (Builder $query) => $query->where('status', 'active'))
            ->with(['dependents' => fn (Builder $query) => $query->where('status', 'active')->orderBy('member_number')])
            ->orderBy('id')
            ->each(function (Member $parent) use ($month, $year, $collectOldestArrearsFirst, &$results, &$householdMemberIds): void {
                $dependents = $parent->dependents;

                $this->applyHouseholdInstallmentsForPeriod(
                    $parent,
                    $dependents,
                    $month,
                    $year,
                    $results,
                    $collectOldestArrearsFirst,
                );

                $householdMemberIds[] = $parent->id;

                foreach ($dependents as $dependent) {
                    $householdMemberIds[] = $dependent->id;
                }
            });

        $memberQuery = $collectOldestArrearsFirst
            ? $this->membersWithCollectableEmisThroughPeriodQuery($month, $year)
            : $this->membersWithPendingEmisQuery($month, $year);

        $memberQuery
            ->when($householdMemberIds !== [], fn (Builder $query) => $query->whereNotIn('id', $householdMemberIds))
            ->each(function (Member $member) use ($month, $year, $collectOldestArrearsFirst, &$results): void {
                $this->applyForMemberForPeriod($member, $month, $year, $results, $collectOldestArrearsFirst);
            });

        return $results;
    }

    /**
     * Members with any unpaid EMI due on or before the selected cycle’s due end.
     */
    public function membersWithCollectableEmisThroughPeriodQuery(int $month, int $year): Builder
    {
        $end = $this->cycles->cycleDueEndAt($month, $year)->toDateString();

        return Member::query()
            ->active()
            ->whereHas('loans', function (Builder $loan) use ($end): void {
                $loan->whereIn('status', ['active', 'transferred'])
                    ->whereHas('installments', function (Builder $installment) use ($end): void {
                        $installment
                            ->whereIn('status', ['pending', 'overdue'])
                            ->where(function (Builder $query): void {
                                $query->whereNull('collection_status')
                                    ->orWhereIn('collection_status', InstallmentCollectionStatus::openCollectionStates());
                            })
                            ->whereDate('due_date', '<=', $end);
                    });
            })
            ->with(['cashAccount', 'parent']);
    }

    public function emiArrearsInstallmentCount(int $month, int $year, ?bool $live = null): int
    {
        return $this->emiArrearsInstallmentsForPeriod($month, $year, $live)->count();
    }

    public function emiArrearsMemberCount(int $month, int $year, ?bool $live = null): int
    {
        return $this->emiArrearsInstallmentsForPeriod($month, $year, $live)
            ->map(fn (LoanInstallment $installment): ?int => $installment->loan?->member_id)
            ->filter()
            ->unique()
            ->count();
    }

    public function emiArrearsInstallmentsQuery(int $month, int $year, ?bool $live = null): Builder
    {
        $installmentIds = $this->emiArrearsInstallmentsForPeriod($month, $year, $live)
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        if ($installmentIds === []) {
            return LoanInstallment::query()->whereRaw('0 = 1');
        }

        return LoanInstallment::query()
            ->whereIn('id', $installmentIds)
            ->with(['loan.member']);
    }

    /**
     * Unpaid installments assigned to labelled cycles before the selected cycle.
     *
     * @return Collection<int, LoanInstallment>
     */
    public function emiArrearsInstallmentsForPeriod(int $month, int $year, ?bool $live = null): Collection
    {
        [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();
        $live ??= $month === $openMonth && $year === $openYear;

        $context = app(LoanDelinquencyService::class)
            ->contributionArrearsEvaluationContext($month, $year, $live);
        $asOf = $context['as_of'];
        $cycleStart = $this->cycles->cycleStartAt($month, $year)->toDateString();

        return LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->where(function (Builder $query): void {
                $query->whereNull('collection_status')
                    ->orWhereIn('collection_status', InstallmentCollectionStatus::openCollectionStates());
            })
            ->whereDate('due_date', '<', $cycleStart)
            ->whereHas('loan', function (Builder $loan): void {
                $loan->whereIn('status', ['active', 'transferred']);
            })
            ->with(['loan.member'])
            ->orderBy('due_date')
            ->get()
            ->filter(function (LoanInstallment $installment) use ($asOf): bool {
                if ($installment->due_date === null) {
                    return false;
                }

                [$cycleMonth, $cycleYear] = $this->cycles->cyclePeriodForDueDate($installment->due_date);

                if (! $asOf->greaterThan($this->cycles->deadline($cycleMonth, $cycleYear))) {
                    return false;
                }

                $member = $installment->loan?->member;

                if (! $member instanceof Member) {
                    return false;
                }

                return ! Contribution::blocksLoanRepaymentForMemberPeriod($member, $cycleMonth, $cycleYear);
            })
            ->values();
    }

    public function emiArrearsAmountTotal(int $month, int $year, ?bool $live = null): float
    {
        return round(
            $this->emiArrearsInstallmentsForPeriod($month, $year, $live)
                ->sum(fn (LoanInstallment $installment): float => (float) $installment->amount + (float) ($installment->late_fee_amount ?? 0)),
            2,
        );
    }

    public function collectableInstallmentsAmountTotal(int $month, int $year): float
    {
        $total = 0.0;

        $this->membersWithCollectableEmisQuery($month, $year)
            ->each(function (Member $member) use ($month, $year, &$total): void {
                foreach ($this->collectableInstallmentsForMemberInPeriod($member, $month, $year) as $installment) {
                    $total += (float) $installment->amount + (float) ($installment->late_fee_amount ?? 0);
                }
            });

        return round($total, 2);
    }

    public function collectedInstallmentsQuery(int $month, int $year): Builder
    {
        [$start, $end] = $this->cycles->cycleDueDateBounds($month, $year);

        return LoanInstallment::query()
            ->where('status', 'paid')
            ->whereBetween('due_date', [$start, $end])
            ->whereHas('loan', fn (Builder $loan): Builder => $loan
                ->whereIn('status', ['active', 'transferred', 'completed', 'early_settled']))
            ->with(['loan.member']);
    }
}
