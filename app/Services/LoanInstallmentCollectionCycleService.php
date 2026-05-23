<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\LoanInstallment;
use App\Support\InstallmentCollectionStatus;

/**
 * EMI collection window parity with contribution cycle (close window + overdue_since).
 */
class LoanInstallmentCollectionCycleService
{
    public function __construct(
        protected ContributionCycleService $cycles,
    ) {}

    /**
     * Mark open EMIs due in the period as overdue when the collection window closes.
     */
    public function closeCollectionWindow(int $month, int $year): int
    {
        $closedAt = $this->cycles->cycleDueEndAt($month, $year);
        $windowStart = $this->cycles->cycleStartAt($month, $year);
        $windowEnd = $closedAt->copy();
        $flagged = 0;

        LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->where(function ($query): void {
                $query->whereNull('collection_status')
                    ->orWhereIn('collection_status', InstallmentCollectionStatus::openCollectionStates());
            })
            ->whereHas('loan', fn ($q) => $q->whereIn('status', ['active', 'transferred']))
            ->whereBetween('due_date', [$windowStart->toDateString(), $windowEnd->toDateString()])
            ->each(function (LoanInstallment $installment) use ($closedAt, &$flagged): void {
                $installment->update([
                    'status' => 'overdue',
                    'collection_status' => InstallmentCollectionStatus::OVERDUE,
                    'overdue_since' => $closedAt,
                    'is_late' => true,
                ]);
                $flagged++;
            });

        return $flagged;
    }

    /**
     * Close EMI windows for the calendar month of each installment due date (batch helper).
     */
    public function closeWindowsForDueMonth(int $month, int $year): int
    {
        return $this->closeCollectionWindow($month, $year);
    }
}
