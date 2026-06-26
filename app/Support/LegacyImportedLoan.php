<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanRepayment;

/**
 * Identifies loans whose repayments were bulk-imported from legacy migration CSVs.
 */
final class LegacyImportedLoan
{
    public static function isLoan(Loan|int $loan): bool
    {
        $loanId = $loan instanceof Loan ? (int) $loan->getKey() : $loan;

        if ($loanId <= 0) {
            return false;
        }

        return LoanRepayment::query()
            ->where('loan_id', $loanId)
            ->where(function ($query): void {
                $query->where('notes', 'like', '%legacy-import:%')
                    ->orWhere('notes', 'like', '%Legacy migration%')
                    ->orWhere('notes', 'like', '%ترحيل البيانات التاريخية%');
            })
            ->exists();
    }

    public static function waiveAutomatedLateFees(Loan|int $loan): int
    {
        $loanModel = $loan instanceof Loan ? $loan : Loan::query()->find($loan);

        if ($loanModel === null) {
            return 0;
        }

        return $loanModel->installments()
            ->where(function ($query): void {
                $query->where('late_fee_amount', '>', 0)
                    ->orWhere('is_late', true)
                    ->orWhereNotNull('overdue_since')
                    ->orWhere('late_fee_tier', '>', 0);
            })
            ->update([
                'late_fee_amount' => 0,
                'is_late' => false,
                'late_fee_tier' => 0,
                'overdue_since' => null,
            ]);
    }
}
