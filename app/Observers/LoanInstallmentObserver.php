<?php

namespace App\Observers;

use App\Models\Tenant\LoanInstallment;
use App\Services\Loans\LoanLedgerService;
use Throwable;

class LoanInstallmentObserver
{
    public function __construct(protected LoanLedgerService $ledger) {}

    public function updated(LoanInstallment $installment): void
    {
        if (
            $installment->wasChanged('status') &&
            $installment->status === 'paid' &&
            $installment->getOriginal('status') !== 'paid'
        ) {
            try {
                $this->ledger->postLoanRepayment($installment);
            } catch (Throwable $e) {
                logger()->error('LoanInstallmentObserver: failed to post repayment', [
                    'installment_id' => $installment->id,
                    'error' => $e->getMessage(),
                ]);
            }

            $installment->loan?->syncPaidOffStatusFromInstallments();
        }
    }
}
