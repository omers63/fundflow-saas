<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Contribution;
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
            ->with(['cashAccount', 'parent'])
            ->orderBy('name');
    }

    /**
     * Members with any unpaid EMI due on or before the open cycle end (open period + arrears).
     */
    public function membersWithCollectableEmisQuery(int $month, int $year): Builder
    {
        $cycleEnd = $this->cycles->cycleDueEndAt($month, $year)->toDateString();

        return Member::query()
            ->active()
            ->whereHas('loans', function (Builder $loan) use ($cycleEnd): void {
                $loan->whereIn('status', ['active', 'transferred'])
                    ->whereHas('installments', function (Builder $installment) use ($cycleEnd): void {
                        $installment
                            ->whereIn('status', ['pending', 'overdue'])
                            ->where(function (Builder $query): void {
                                $query->whereNull('collection_status')
                                    ->orWhereIn('collection_status', InstallmentCollectionStatus::openCollectionStates());
                            })
                            ->whereDate('due_date', '<=', $cycleEnd);
                    });
            })
            ->with(['cashAccount', 'parent'])
            ->orderBy('name');
    }

    public function pendingMemberCount(int $month, int $year): int
    {
        return $this->membersWithCollectableEmisQuery($month, $year)->count();
    }

    public function collectedInstallmentCount(int $month, int $year): int
    {
        return $this->collectedInstallmentsQuery($month, $year)->count();
    }

    /**
     * @return Collection<int, LoanInstallment>
     */
    public function collectableInstallmentsForMember(Member $member, int $month, int $year): Collection
    {
        $cycleEnd = $this->cycles->cycleDueEndAt($month, $year)->toDateString();

        return LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->where(function (Builder $query): void {
                $query->whereNull('collection_status')
                    ->orWhereIn('collection_status', InstallmentCollectionStatus::openCollectionStates());
            })
            ->whereHas('loan', function (Builder $loan) use ($member): void {
                $loan->whereIn('status', ['active', 'transferred'])
                    ->where('member_id', $member->id);
            })
            ->whereDate('due_date', '<=', $cycleEnd)
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
    public function applyForMember(Member $member, int $month, int $year): string
    {
        $before = $this->pendingInstallmentCountForMember($member, $month, $year);

        if ($before === 0) {
            return 'none';
        }

        $this->installmentCollection->onMemberCashIncreased($member->fresh() ?? $member);

        $after = $this->pendingInstallmentCountForMember($member->fresh() ?? $member, $month, $year);
        $settled = $before - $after;

        if ($settled === 0) {
            return 'no_cash';
        }

        if ($after > 0) {
            return 'partial';
        }

        return 'collected';
    }

    /**
     * @return Collection<int, LoanInstallment>
     */
    public function collectableInstallmentsForMemberInPeriod(Member $member, int $month, int $year): Collection
    {
        [$start, $end] = $this->cycles->cycleDueDateBounds($month, $year);

        return LoanInstallment::query()
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
     * Collect EMIs due in one cycle period from member cash (manual batch / cycle run).
     *
     * @param  array{applied: Collection, insufficient: Collection, skipped: Collection}  $results
     * @return 'collected'|'partial'|'no_cash'|'none'
     */
    public function applyForMemberForPeriod(Member $member, int $month, int $year, array &$results = []): string
    {
        $before = $this->pendingInstallmentCountForMemberInPeriod($member, $month, $year);

        if ($before === 0) {
            $results['skipped'][] = $member;

            return 'none';
        }

        $this->installmentCollection->onMemberCashIncreasedForPeriod($member->fresh() ?? $member, $month, $year);

        $after = $this->pendingInstallmentCountForMemberInPeriod($member->fresh() ?? $member, $month, $year);
        $settled = $before - $after;

        if ($settled === 0) {
            $results['insufficient'][] = [
                'member' => $member,
                'balance' => $member->fresh()->getCashBalance(),
                'required' => $this->requiredCashForMemberInPeriod($member, $month, $year),
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
    ): void {
        $parent = $parent->fresh() ?? $parent;
        $parent->unsetRelation('accounts');

        if ($dependents->isNotEmpty() && $parent->getCashBalance() >= 0.00001) {
            $this->cycles->applyDependentAllocationForParentForPeriod($parent, $month, $year);
        }

        $this->applyForMemberForPeriod($parent, $month, $year, $results);

        foreach ($dependents as $dependent) {
            $this->applyForMemberForPeriod($dependent->fresh() ?? $dependent, $month, $year, $results);
        }
    }

    /**
     * @return array{applied: Collection<int, Member>, insufficient: Collection<int, array{member: Member, balance: float, required: float}>, skipped: Collection<int, Member>}
     */
    public function applyInstallmentsForPeriod(int $month, int $year): array
    {
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
            ->each(function (Member $parent) use ($month, $year, &$results, &$householdMemberIds): void {
                $dependents = $parent->dependents;

                $this->applyHouseholdInstallmentsForPeriod($parent, $dependents, $month, $year, $results);

                $householdMemberIds[] = $parent->id;

                foreach ($dependents as $dependent) {
                    $householdMemberIds[] = $dependent->id;
                }
            });

        $this->membersWithPendingEmisQuery($month, $year)
            ->when($householdMemberIds !== [], fn (Builder $query) => $query->whereNotIn('id', $householdMemberIds))
            ->each(function (Member $member) use ($month, $year, &$results): void {
                $this->applyForMemberForPeriod($member, $month, $year, $results);
            });

        return $results;
    }

    public function collectedInstallmentsQuery(int $month, int $year): Builder
    {
        [$start, $end] = $this->cycles->cycleDueDateBounds($month, $year);

        return LoanInstallment::query()
            ->where('status', 'paid')
            ->whereBetween('due_date', [$start, $end])
            ->whereHas('loan', fn (Builder $loan): Builder => $loan->whereIn('status', ['active', 'transferred', 'completed', 'early_settled']))
            ->with(['loan.member'])
            ->orderByDesc('paid_at');
    }
}
