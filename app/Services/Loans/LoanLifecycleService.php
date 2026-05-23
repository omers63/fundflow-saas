<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanDisbursement;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Notifications\Tenant\GuarantorLoanApplicationNotification;
use App\Notifications\Tenant\LoanApprovedNotification;
use App\Notifications\Tenant\LoanDisbursedNotification;
use App\Notifications\Tenant\LoanPartialDisbursementNotification;
use App\Notifications\Tenant\LoanRejectedNotification;
use App\Notifications\Tenant\LoanSubmittedNotification;
use App\Notifications\Tenant\NewLoanApplicationNotification;
use App\Support\LoanSettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

final class LoanLifecycleService
{
    public function __construct(
        private LoanLedgerService $ledger,
        private LoanEligibilityService $eligibility,
    ) {}

    /**
     * @return array{eligible: bool, reasons: string[]}
     */
    public function checkEligibility(Member $member): array
    {
        $reason = $this->eligibility->getIneligibilityReason($member);

        return [
            'eligible' => $reason === '',
            'reasons' => $reason !== '' ? [$reason] : [],
        ];
    }

    public function validateLoanAmount(Member $member, float $amount): ?string
    {
        if ($amount <= 0) {
            return __('Loan amount must be greater than zero.');
        }

        $max = min(
            $this->eligibility->maxLoanAmount($member),
            LoanSettings::maxLoanAmountForMember($member->getFundBalance()),
        );

        if ($amount > $max + 0.01) {
            return __('Maximum loan amount for this member is :max.', [
                'max' => number_format($max, 2),
            ]);
        }

        if (LoanTier::forAmount($amount) === null) {
            return __('No loan tier covers this amount. Contact an administrator.');
        }

        return null;
    }

    public function applyForLoan(
        Member $member,
        float $amountRequested,
        ?string $purpose = null,
        ?int $guarantorMemberId = null,
        bool $isEmergency = false,
        bool $hasGraceCycle = true,
        ?int $graceCycles = null,
        ?string $witness1Name = null,
        ?string $witness1Phone = null,
        ?string $witness2Name = null,
        ?string $witness2Phone = null,
    ): Loan {
        $check = $this->checkEligibility($member);
        if (! $check['eligible']) {
            throw new InvalidArgumentException(implode(' ', $check['reasons']));
        }

        if ($error = $this->validateLoanAmount($member, $amountRequested)) {
            throw new InvalidArgumentException($error);
        }

        if (LoanSettings::guarantorRequiredForAmount($member, $amountRequested) && $guarantorMemberId === null) {
            throw new InvalidArgumentException(__('A guarantor is required when the loan amount exceeds your fund balance.'));
        }

        if ($guarantorMemberId !== null && (int) $guarantorMemberId === (int) $member->id) {
            throw new InvalidArgumentException(__('You cannot be your own guarantor.'));
        }

        $loanTier = LoanTier::forAmount($amountRequested);

        $loan = Loan::create([
            'member_id' => $member->id,
            'loan_tier_id' => $loanTier?->id,
            'amount' => $amountRequested,
            'amount_requested' => $amountRequested,
            'interest_rate' => LoanSettings::defaultInterestRate(),
            'term_months' => LoanSettings::defaultTermMonths(),
            'monthly_repayment' => 0,
            'total_repaid' => 0,
            'purpose' => $purpose,
            'guarantor_member_id' => $guarantorMemberId,
            'witness1_name' => $witness1Name,
            'witness1_phone' => $witness1Phone,
            'witness2_name' => $witness2Name,
            'witness2_phone' => $witness2Phone,
            'is_emergency' => $isEmergency,
            'has_grace_cycle' => $hasGraceCycle,
            'grace_cycles' => max(0, min(2, $graceCycles ?? ($hasGraceCycle ? 1 : 0))),
            'status' => 'pending',
            'applied_at' => now(),
        ]);

        $this->notifyMember($loan, new LoanSubmittedNotification($loan));

        if ($guarantorMemberId !== null) {
            $loan->loadMissing('guarantor.user');
            $guarantorUser = $loan->guarantor?->user;
            if ($guarantorUser !== null) {
                $this->notifyMember($loan, new GuarantorLoanApplicationNotification($loan), $guarantorUser);
            }
        }

        User::query()->where('is_admin', true)->each(
            fn (User $admin): mixed => $admin->notify(new NewLoanApplicationNotification($loan))
        );

        return $loan;
    }

    public function approveLoan(
        Loan $loan,
        float $amountApproved,
        bool $isEmergency = false,
        bool $hasGraceCycle = true,
        ?int $graceCycles = null,
        ?CarbonInterface $approvedAt = null,
        ?int $approvedById = null,
    ): void {
        if ($loan->status !== 'pending') {
            throw new InvalidArgumentException(__('Only pending loans can be approved.'));
        }

        $loanTier = LoanTier::forAmount($amountApproved);
        if ($loanTier === null) {
            throw new InvalidArgumentException(__('No loan tier covers the approved amount.'));
        }

        $fundTier = $isEmergency
            ? FundTier::emergency()
            : FundTier::forLoanTier($loanTier->id);

        if ($fundTier === null) {
            throw new InvalidArgumentException(__('No active fund tier is configured for this loan.'));
        }

        $fundBal = (float) ($loan->member->fundAccount?->balance ?? 0);
        $threshold = LoanSettings::settlementThreshold();
        $count = Loan::computeInstallmentsCount(
            $amountApproved,
            $fundBal,
            (float) $loanTier->min_monthly_installment,
            $threshold,
        );

        $at = $approvedAt ?? now();

        $loan->update([
            'status' => 'approved',
            'amount_approved' => $amountApproved,
            'is_emergency' => $isEmergency,
            'installments_count' => $count,
            'loan_tier_id' => $loanTier->id,
            'fund_tier_id' => $fundTier->id,
            'queue_position' => null,
            'approved_at' => $at,
            'approved_by_id' => $approvedById ?? auth()->id(),
            'settlement_threshold' => $threshold,
            'has_grace_cycle' => $hasGraceCycle,
            'grace_cycles' => max(0, min(2, $graceCycles ?? ($hasGraceCycle ? 1 : 0))),
        ]);

        LoanQueueOrderingService::resequenceFundTier($fundTier->id);
        $loan->refresh();

        $this->notifyMember($loan, new LoanApprovedNotification(
            amount: $amountApproved,
            installments: $count,
            dueDate: $at->copy()->addMonths($count)->format('d M Y'),
        ));
    }

    public function rejectLoan(Loan $loan, string $reason): void
    {
        if ($loan->status !== 'pending') {
            throw new InvalidArgumentException(__('Only pending loans can be rejected.'));
        }

        $loan->update([
            'status' => 'rejected',
            'rejection_reason' => $reason,
            'rejected_at' => now(),
        ]);

        $this->notifyMember($loan, new LoanRejectedNotification($reason));
    }

    public function cancelLoan(Loan $loan, ?string $reason = null): void
    {
        if ($loan->status !== 'pending') {
            throw new InvalidArgumentException(__('Only pending loans can be cancelled.'));
        }

        $loan->update([
            'status' => 'cancelled',
            'cancellation_reason' => $reason,
            'cancelled_at' => now(),
        ]);
    }

    public function disbursePartial(
        Loan $loan,
        float $amount,
        ?string $notes = null,
        ?CarbonInterface $disbursedAt = null,
        bool $force = false,
        bool $allowNegativeMasterFundBalance = false,
    ): void {
        if (! in_array($loan->status, ['approved', 'partially_disbursed'], true)) {
            throw new InvalidArgumentException(__('Only approved or partially disbursed loans can receive disbursements.'));
        }

        $loan->loadMissing(['fundTier', 'member.accounts', 'loanTier']);
        $remaining = $loan->remainingToDisburse();
        $fundTier = $loan->fundTier;
        $declaredCap = $fundTier ? max(0.0, (float) $fundTier->allocated_amount) : $remaining;
        $masterBal = (float) (Account::masterFund()?->balance ?? 0);

        if ($amount <= 0) {
            throw new InvalidArgumentException(__('Enter a disbursement amount.'));
        }

        if ($amount > $remaining + 0.01) {
            throw new InvalidArgumentException(__('Amount exceeds remaining to disburse.'));
        }

        if (! $force && $amount > $declaredCap + 0.01) {
            throw new InvalidArgumentException(__('Amount exceeds fund tier declared pool.'));
        }

        if (! $allowNegativeMasterFundBalance && $amount > $masterBal + 0.01) {
            throw new InvalidArgumentException(__('Amount exceeds master fund balance.'));
        }

        $memberFundBalanceBefore = (float) ($loan->member->fundAccount?->balance ?? 0);
        $at = $disbursedAt ?? now();

        $disbursement = LoanDisbursement::create([
            'loan_id' => $loan->id,
            'amount' => $amount,
            'member_portion' => 0,
            'master_portion' => 0,
            'disbursed_at' => $at,
            'disbursed_by_id' => auth()->id(),
            'notes' => $notes,
        ]);

        try {
            $this->ledger->postPartialLoanDisbursement(
                $loan,
                $amount,
                $disbursement,
                $at,
                $allowNegativeMasterFundBalance,
            );
        } catch (Throwable $e) {
            $disbursement->delete();

            throw $e;
        }

        $loan->refresh();

        if ($loan->isFullyDisbursed()) {
            $this->activateAfterFullDisbursement($loan, $at, $memberFundBalanceBefore);
        } else {
            $loan->update([
                'status' => 'partially_disbursed',
                'lifecycle_stage' => 'partially_disbursed',
            ]);

            $this->notifyMember($loan, new LoanPartialDisbursementNotification(
                disbursement: $disbursement,
                totalDisbursed: (float) $loan->amount_disbursed,
                amountApproved: (float) $loan->amount_approved,
            ));
        }

        if ($loan->fund_tier_id) {
            LoanQueueOrderingService::resequenceFundTier($loan->fund_tier_id);
        }
    }

    public function markBankPayout(Loan $loan, ?CarbonInterface $payoutAt = null): void
    {
        if (! in_array($loan->status, ['approved', 'partially_disbursed', 'active'], true)) {
            throw new InvalidArgumentException(__('Bank payout can only be recorded for approved or active loans.'));
        }

        if (! $loan->isFullyDisbursed()) {
            throw new InvalidArgumentException(__('Loan must be fully disbursed on the ledger before bank payout.'));
        }

        $loan->update(['payout_at' => $payoutAt ?? now()]);
    }

    private function activateAfterFullDisbursement(
        Loan $loan,
        CarbonInterface $disbursedAt,
        float $memberFundBalanceBefore,
    ): void {
        $amountApproved = (float) $loan->amount_approved;
        $minInstall = (float) ($loan->loanTier?->min_monthly_installment ?? 1000);
        $threshold = (float) $loan->settlement_threshold;
        $count = Loan::computeInstallmentsCount($amountApproved, $memberFundBalanceBefore, $minInstall, $threshold);

        $graceCycles = $loan->grace_cycles ?? ($loan->has_grace_cycle ? 1 : 0);
        $exemption = Loan::computeExemptionAndFirstRepayment(Carbon::parse($disbursedAt), (int) $graceCycles);
        $exemption = Loan::finalizeExemptionForDisbursement($loan->member, $exemption, Carbon::parse($disbursedAt));

        DB::transaction(function () use ($loan, $disbursedAt, $exemption, $count, $minInstall, $amountApproved, $memberFundBalanceBefore): void {
            $memberPortion = min(max(0.0, $memberFundBalanceBefore), $amountApproved);
            $masterPortion = $amountApproved - $memberPortion;

            $loan->update([
                'status' => 'active',
                'lifecycle_stage' => 'active',
                'installments_count' => $count,
                'disbursed_at' => $disbursedAt,
                'due_date' => Carbon::parse($disbursedAt)->addMonths($count)->toDateString(),
                'member_portion' => $memberPortion,
                'master_portion' => $masterPortion,
            ] + $exemption);

            try {
                $this->ledger->recognizeMemberPortionAgainstLoanPrincipal($loan->fresh(), $disbursedAt);
            } catch (Throwable) {
            }

            $startDate = Carbon::create(
                $exemption['first_repayment_year'],
                $exemption['first_repayment_month'],
                5,
            );

            for ($i = 1; $i <= $count; $i++) {
                LoanInstallment::create([
                    'loan_id' => $loan->id,
                    'installment_number' => $i,
                    'amount' => $minInstall,
                    'due_date' => $startDate->copy()->addMonths($i - 1)->toDateString(),
                    'status' => 'pending',
                ]);
            }
        });

        $loan->refresh();
        $this->notifyMember($loan, new LoanDisbursedNotification($loan));
    }

    private function notifyMember(Loan $loan, object $notification, ?User $user = null): void
    {
        try {
            $loan->loadMissing('member.user');
            $recipient = $user ?? $loan->member->user;
            $recipient?->notify($notification);
        } catch (Throwable $e) {
            logger()->warning('LoanLifecycleService: notification failed', [
                'loan_id' => $loan->id,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
