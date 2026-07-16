<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Notifications\Tenant\LoanGuarantorTransferAdminNotification;
use App\Notifications\Tenant\LoanGuarantorTransferNotification;
use App\Services\FundAuditLogService;
use App\Services\MemberStatusService;
use App\Services\OperationalReviewWorkflowService;
use App\Support\BusinessDay;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class LoanGuarantorTransferService
{
    public function __construct(
        protected FundAuditLogService $audit,
    ) {}

    public function transferToGuarantor(Loan $loan): void
    {
        if ($loan->status !== 'active') {
            throw new InvalidArgumentException(__('Only active loans can be transferred to the guarantor.'));
        }

        $guarantor = $loan->guarantor;

        if ($guarantor === null) {
            throw new InvalidArgumentException(__('This loan has no guarantor assigned.'));
        }

        if ($loan->transferred_to_guarantor_at !== null) {
            throw new InvalidArgumentException(__('This loan has already been transferred to the guarantor.'));
        }

        if (! $loan->installments()->where('status', 'overdue')->exists()) {
            throw new InvalidArgumentException(__('Mark installments overdue before transferring liability.'));
        }

        $borrower = $loan->member;

        DB::transaction(function () use ($loan, $guarantor, $borrower): void {
            $originalBorrowerId = $loan->original_borrower_member_id ?? $borrower->id;

            $loan->update([
                'original_borrower_member_id' => $originalBorrowerId,
                'member_id' => $guarantor->id,
                'status' => 'transferred',
                'lifecycle_stage' => 'transferred',
                'transferred_to_guarantor_at' => BusinessDay::now(),
                'guarantor_liability_transferred_at' => BusinessDay::now(),
            ]);

            $borrower->refresh();
            app(MemberStatusService::class)->suspendForGuarantorTransfer($borrower);

            $remaining = $this->remainingGuarantorObligation($loan->fresh());

            $loan->installments()
                ->whereIn('status', ['pending', 'overdue'])
                ->forceDelete();

            $this->rebuildGuarantorSchedule($loan->fresh(), $guarantor, $remaining);
        });

        $this->audit->log('LOAN_TRANSFERRED_TO_GUARANTOR', 'loan', $loan->fresh(), $guarantor, [
            'original_borrower_id' => $borrower->id,
            'guarantor_id' => $guarantor->id,
        ]);

        $loan = $loan->fresh();
        $this->notifyTransferParties($loan, $borrower, $guarantor);
    }

    protected function notifyTransferParties(Loan $loan, Member $borrower, Member $guarantor): void
    {
        try {
            $borrower->loadMissing('user');
            $guarantor->loadMissing('user');

            $borrower->user?->notify(new LoanGuarantorTransferNotification($loan, $borrower, $guarantor, 'borrower'));
            $guarantor->user?->notify(new LoanGuarantorTransferNotification($loan, $borrower, $guarantor, 'guarantor'));

            app(OperationalReviewWorkflowService::class)
                ->notifyAdmins(new LoanGuarantorTransferAdminNotification($loan, $borrower, $guarantor));
        } catch (\Throwable $e) {
            logger()->warning('LoanGuarantorTransferService: notification failed', [
                'loan_id' => $loan->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Remaining fund-slice obligation the guarantor inherits (master portion only).
     *
     * Settlement threshold is part of the normal borrower schedule only; it is not
     * transferred to the guarantor.
     */
    protected function remainingGuarantorObligation(Loan $loan): float
    {
        $masterPortion = max(0.0, (float) $loan->master_portion);
        $repaidToMaster = (float) ($loan->repaid_to_master ?? 0);

        return max(0.0, $masterPortion - $repaidToMaster);
    }

    protected function rebuildGuarantorSchedule(Loan $loan, Member $guarantor, float $obligation): void
    {
        $emi = (float) ($loan->loanTier?->min_monthly_installment ?? $loan->monthly_repayment ?? 0);

        if ($emi <= 0) {
            throw new InvalidArgumentException(__('Cannot rebuild schedule without a valid EMI amount.'));
        }

        if ($obligation <= 0.01) {
            $loan->update(['installments_count' => 0]);

            return;
        }

        $remaining = $obligation;
        $position = 1;
        $startNumber = ((int) $loan->installments()->max('installment_number')) + 1;
        $due = BusinessDay::now()->addMonthNoOverflow()->startOfMonth()->addDays(4);
        $installmentsCount = (int) ceil($obligation / $emi);

        while ($remaining > 0.01) {
            $amount = Loan::scheduleInstallmentAmount(
                $position,
                $installmentsCount,
                $emi,
                $obligation,
            );

            LoanInstallment::create([
                'loan_id' => $loan->id,
                'installment_number' => $startNumber + $position - 1,
                'amount' => $amount,
                'due_date' => $due->copy(),
                'status' => 'pending',
                'collection_status' => 'pending',
                'amount_collected' => 0,
            ]);

            $remaining -= $amount;
            $position++;
            $due = $due->copy()->addMonthNoOverflow();
        }

        $loan->update(['installments_count' => $loan->installments()->count()]);
    }
}
