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
use App\Support\InstallmentCollectionStatus;
use App\Support\LoanRepaymentWindowPolicy;
use Carbon\Carbon;
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
    public function remainingGuarantorObligation(Loan $loan): float
    {
        return LoanTransferPreview::remainingObligation(
            max(0.0, (float) $loan->master_portion),
            (float) ($loan->repaid_to_master ?? 0),
        );
    }

    public function rebuildGuarantorSchedule(Loan $loan, Member $guarantor, float $obligation): void
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

    /**
     * Move a guarantor-transferred loan back to the original borrower and rebuild
     * unpaid EMI slots from loan terms. Reverse in-window guarantor collections first.
     */
    public function restoreToOriginalBorrower(Loan $loan): void
    {
        $originalBorrowerId = $loan->original_borrower_member_id;

        if ($originalBorrowerId === null) {
            throw new InvalidArgumentException(__('This loan has no original borrower on record.'));
        }

        $borrower = Member::query()->find($originalBorrowerId);

        if ($borrower === null) {
            throw new InvalidArgumentException(__('Original borrower member not found.'));
        }

        DB::transaction(function () use ($loan, $borrower): void {
            $loan->installments()
                ->whereIn('status', ['pending', 'overdue'])
                ->forceDelete();

            $loan->update([
                'member_id' => $borrower->id,
                'status' => 'active',
                'lifecycle_stage' => 'active',
                'transferred_to_guarantor_at' => null,
                'guarantor_liability_transferred_at' => null,
            ]);

            $this->rebuildOriginalBorrowerUnpaidSchedule($loan->fresh());

            $borrower->refresh();

            if ($borrower->status === 'inactive' && $borrower->frozen_at === null) {
                app(MemberStatusService::class)->restoreInactive($borrower);
            }
        });

        $this->audit->log('LOAN_RESTORED_FROM_GUARANTOR', 'loan', $loan->fresh(), $borrower, [
            'original_borrower_id' => $borrower->id,
            'guarantor_id' => $loan->guarantor_member_id,
        ]);
    }

    private function rebuildOriginalBorrowerUnpaidSchedule(Loan $loan): void
    {
        $loan->loadMissing('loanTier');

        $amountApproved = (float) $loan->amount_approved;
        $memberPortion = (float) $loan->member_portion;
        $minInstall = (float) ($loan->loanTier?->min_monthly_installment ?? $loan->monthly_repayment ?? 1000);
        $threshold = (float) $loan->settlement_threshold;
        $count = Loan::computeInstallmentsCountFromPortions(
            $amountApproved,
            $memberPortion,
            $minInstall,
            $threshold,
        );

        if ($count <= 0) {
            $loan->update(['installments_count' => $loan->installments()->count()]);

            return;
        }

        $policy = app(LoanRepaymentWindowPolicy::class);
        $asOf = BusinessDay::today()->startOfDay();
        $firstPeriod = $this->originalScheduleStart($loan);

        $existingNumbers = $loan->installments()
            ->pluck('installment_number')
            ->map(fn ($number): int => (int) $number)
            ->all();

        for ($i = 1; $i <= $count; $i++) {
            if (in_array($i, $existingNumbers, true)) {
                continue;
            }

            $period = $firstPeriod->copy()->addMonths($i - 1);
            $due = $policy->installmentDueDateForCycle((int) $period->month, (int) $period->year);
            $overdue = $due->copy()->startOfDay()->lte($asOf);

            LoanInstallment::create([
                'loan_id' => $loan->id,
                'installment_number' => $i,
                'amount' => Loan::scheduleInstallmentAmount(
                    $i,
                    $count,
                    $minInstall,
                    $loan->fullRepaymentThreshold(),
                ),
                'due_date' => $due->toDateString(),
                'status' => $overdue ? 'overdue' : 'pending',
                'collection_status' => $overdue
                    ? InstallmentCollectionStatus::OVERDUE
                    : InstallmentCollectionStatus::PENDING,
                'overdue_since' => $overdue ? $due->copy()->endOfDay() : null,
                'is_late' => $overdue,
                'amount_collected' => 0,
            ]);
        }

        $loan->update(['installments_count' => $count]);
    }

    private function originalScheduleStart(Loan $loan): Carbon
    {
        if ($loan->first_repayment_month && $loan->first_repayment_year) {
            return Carbon::create(
                (int) $loan->first_repayment_year,
                (int) $loan->first_repayment_month,
                1,
            )->startOfMonth();
        }

        $earliest = $loan->installments()->orderBy('installment_number')->first();

        if ($earliest?->due_date !== null) {
            return Carbon::parse($earliest->due_date)
                ->startOfMonth()
                ->subMonths(max(0, (int) $earliest->installment_number - 1));
        }

        $from = $loan->disbursed_at ?? $loan->applied_at ?? BusinessDay::now();

        return Carbon::parse($from)->startOfMonth();
    }
}
