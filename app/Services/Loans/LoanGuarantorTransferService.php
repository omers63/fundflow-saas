<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\FundAuditLogService;
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

            $borrower->update(['status' => 'suspended']);

            $remaining = $this->remainingGuarantorObligation($loan->fresh());

            $loan->installments()
                ->whereIn('status', ['pending', 'overdue'])
                ->delete();

            $this->rebuildGuarantorSchedule($loan->fresh(), $guarantor, $remaining);
        });

        $this->audit->log('LOAN_TRANSFERRED_TO_GUARANTOR', 'loan', $loan->fresh(), $guarantor, [
            'original_borrower_id' => $borrower->id,
            'guarantor_id' => $guarantor->id,
        ]);
    }

    protected function remainingGuarantorObligation(Loan $loan): float
    {
        $masterPortion = max(0.0, (float) $loan->master_portion);
        $repaidToMaster = (float) ($loan->repaid_to_master ?? 0);
        $thresholdPct = (float) ($loan->settlement_threshold ?? 0);
        $thresholdAddon = (float) $loan->amount_approved * $thresholdPct;

        return max(0.0, ($masterPortion - $repaidToMaster) + $thresholdAddon);
    }

    protected function rebuildGuarantorSchedule(Loan $loan, Member $guarantor, float $obligation): void
    {
        $emi = (float) ($loan->loanTier?->min_monthly_installment ?? $loan->monthly_repayment ?? 0);

        if ($emi <= 0) {
            throw new InvalidArgumentException(__('Cannot rebuild schedule without a valid EMI amount.'));
        }

        $remaining = $obligation;
        $number = 1;
        $due = BusinessDay::now()->addMonthNoOverflow()->startOfMonth()->addDays(4);

        while ($remaining > 0.01) {
            $amount = min($emi, $remaining);

            LoanInstallment::create([
                'loan_id' => $loan->id,
                'installment_number' => $number,
                'amount' => round($amount, 2),
                'due_date' => $due->copy(),
                'status' => 'pending',
                'collection_status' => 'pending',
                'amount_collected' => 0,
            ]);

            $remaining -= $amount;
            $number++;
            $due = $due->copy()->addMonthNoOverflow();
        }
    }
}
