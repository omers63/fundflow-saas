<?php

namespace App\Observers;

use App\Models\Tenant\LoanInstallment;
use App\Services\Loans\LoanLedgerService;
use App\Services\Loans\LoanRepaymentLogService;
use Throwable;

class LoanInstallmentObserver
{
    public function __construct(
        protected LoanLedgerService $ledger,
        protected LoanRepaymentLogService $repaymentLog,
    ) {}

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

            if (! LoanRepaymentLogService::isSuppressingInstallmentLogs()) {
                try {
                    $this->repaymentLog->recordInstallmentRepayment($installment);
                } catch (Throwable $e) {
                    logger()->error('LoanInstallmentObserver: failed to log repayment row', [
                        'installment_id' => $installment->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $installment->loan?->syncPaidOffStatusFromInstallments();
        }
    }
}
