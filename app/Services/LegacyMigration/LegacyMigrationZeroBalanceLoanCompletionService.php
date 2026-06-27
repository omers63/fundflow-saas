<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;

/**
 * Marks open legacy-imported loans as completed when no installment balance remains.
 */
final class LegacyMigrationZeroBalanceLoanCompletionService
{
    private const float TOLERANCE = 0.02;

    /**
     * @return array{completed: int, loan_ids: list<int>}
     */
    public function completeAll(): array
    {
        $completed = 0;
        /** @var list<int> $loanIds */
        $loanIds = [];

        Loan::query()
            ->whereIn('status', ['active', 'transferred'])
            ->whereNotNull('disbursed_at')
            ->orderBy('id')
            ->each(function (Loan $loan) use (&$completed, &$loanIds): void {
                if ($this->completeIfZeroOutstanding($loan)) {
                    $completed++;
                    $loanIds[] = (int) $loan->id;
                }
            });

        return [
            'completed' => $completed,
            'loan_ids' => $loanIds,
        ];
    }

    public function completeIfZeroOutstanding(Loan $loan): bool
    {
        if (! in_array($loan->status, ['active', 'transferred'], true)) {
            return false;
        }

        if ($loan->getOutstandingBalance() > self::TOLERANCE) {
            return false;
        }

        if ($loan->isFullyMemberFundedAtDisbursement() && $loan->hasNoRepaymentScheduleObligation()) {
            $loan->completeAsFullyMemberFundedLegacyImport($loan->disbursed_at);

            return $loan->fresh()->isCompleted();
        }

        if ($loan->status === 'active') {
            $loan->syncPaidOffStatusFromInstallments();
            $loan->refresh();

            if ($loan->isCompleted()) {
                return true;
            }
        }

        $this->markLoanCompleted($loan);

        return $loan->fresh()->status === 'completed';
    }

    private function markLoanCompleted(Loan $loan): void
    {
        $settledAt = $loan->repayments()->max('paid_at')
            ?? $loan->installments()->whereNotNull('paid_at')->max('paid_at')
            ?? BusinessDay::now();

        $hasUnpaid = $loan->installments()
            ->whereIn('status', ['pending', 'overdue'])
            ->exists();

        if ($hasUnpaid) {
            $this->settleRemainingInstallments($loan, $settledAt);
        }

        $loan->update([
            'status' => 'completed',
            'settled_at' => $settledAt,
        ]);
    }

    private function settleRemainingInstallments(Loan $loan, CarbonInterface|string $settledAt): void
    {
        LoanInstallment::withoutEvents(function () use ($loan, $settledAt): void {
            $loan->installments()
                ->whereIn('status', ['pending', 'overdue'])
                ->update([
                    'status' => 'paid',
                    'paid_at' => $settledAt,
                    'is_late' => false,
                    'late_fee_amount' => 0,
                    'late_fee_tier' => 0,
                    'overdue_since' => null,
                ]);
        });
    }
}
