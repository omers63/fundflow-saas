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
    ) {
    }

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

                return !Contribution::activePeriodExists((int) $member->id, $cycleMonth, $cycleYear);
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

    public function collectedInstallmentsQuery(int $month, int $year): Builder
    {
        [$start, $end] = $this->cycles->cycleDueDateBounds($month, $year);

        return LoanInstallment::query()
            ->where('status', 'paid')
            ->whereBetween('due_date', [$start, $end])
            ->whereHas('loan', fn(Builder $loan): Builder => $loan->whereIn('status', ['active', 'transferred', 'completed', 'early_settled']))
            ->with(['loan.member'])
            ->orderByDesc('paid_at');
    }
}
