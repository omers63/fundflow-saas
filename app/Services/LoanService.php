<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Services\Loans\LoanEarlySettlementService;
use App\Services\Loans\LoanLifecycleService;
use App\Services\Loans\LoanRepaymentService;
use App\Support\LoanSettings;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/**
 * Facade for loan workflows (delegates to legacy-aligned lifecycle services).
 */
class LoanService
{
    public function __construct(
        private LoanLifecycleService $lifecycle,
        private LoanRepaymentService $repayments,
        private LoanEarlySettlementService $earlySettlement,
    ) {}

    /**
     * @return array{eligible: bool, reasons: string[]}
     */
    public function checkEligibility(Member $member): array
    {
        return $this->lifecycle->checkEligibility($member);
    }

    public function validateLoanAmount(Member $member, float $amount): ?string
    {
        return $this->lifecycle->validateLoanAmount($member, $amount);
    }

    public function applyForLoan(
        Member $member,
        float $amount,
        float $interestRate = 0,
        int $termMonths = 0,
        ?string $purpose = null,
    ): Loan {
        return $this->lifecycle->applyForLoan($member, $amount, $purpose);
    }

    public function approveLoan(Loan $loan, ?float $amountApproved = null): void
    {
        $amount = $amountApproved ?? (float) $loan->amount_requested;
        $this->lifecycle->approveLoan($loan, $amount);
    }

    public function rejectLoan(Loan $loan, string $reason): void
    {
        $this->lifecycle->rejectLoan($loan, $reason);
    }

    public function cancelLoan(Loan $loan): void
    {
        $this->lifecycle->cancelLoan($loan);
    }

    public function disburseLoan(Loan $loan, ?float $amount = null): void
    {
        $loan->loadMissing('fundTier');
        $toDisburse = $amount ?? $loan->remainingToDisburse();
        if ($toDisburse <= 0) {
            throw new InvalidArgumentException(__('Nothing remains to disburse.'));
        }

        $this->lifecycle->disbursePartial($loan, $toDisburse);
    }

    public function recordRepayment(Loan $loan, float $amount, ?string $notes = null): LoanRepayment
    {
        if ($loan->status !== 'active') {
            throw new InvalidArgumentException(__('Repayments can only be recorded on active loans.'));
        }

        $installment = $loan->installments()
            ->whereIn('status', ['pending', 'overdue'])
            ->orderBy('installment_number')
            ->first();

        if ($installment === null) {
            throw new InvalidArgumentException(__('No unpaid installments found.'));
        }

        $loan->ensureScheduleInstallmentAmount($installment);
        $installment->refresh();

        $expectedAmount = (float) $installment->amount;
        if (abs($amount - $expectedAmount) > 0.02) {
            throw new InvalidArgumentException(__('Use early settlement for partial or lump-sum payoffs.'));
        }

        [$month, $year] = $this->periodFromInstallment($installment);
        $this->repayments->applyOne($loan, $month, $year);

        return $loan->repayments()->latest('id')->first()
            ?? new LoanRepayment(['loan_id' => $loan->id, 'amount' => $amount, 'notes' => $notes]);
    }

    public function earlySettle(Loan $loan): void
    {
        $this->earlySettlement->earlySettle($loan);
    }

    /**
     * @param  'roll_up'|'skip_future'  $option
     */
    public function settleLoan(Loan $loan, float $amount, string $option = 'roll_up'): void
    {
        $this->earlySettlement->settle($loan, $amount, $option);
    }

    public static function computeMonthlyRepayment(float $amount, float $interestRate, int $termMonths): float
    {
        $tier = LoanTier::forAmount($amount);
        $minInstall = (float) ($tier?->min_monthly_installment ?? 1000);
        $threshold = LoanSettings::settlementThreshold();
        $fundBal = $amount / 2;
        $count = max(1, Loan::computeInstallmentsCount($amount, $fundBal, $minInstall, $threshold));

        return round($amount / $count, 2);
    }

    public static function computeTotalDue(float $amount, float $interestRate): float
    {
        return $amount + ($amount * $interestRate / 100);
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function periodFromInstallment(LoanInstallment $installment): array
    {
        $due = Carbon::parse($installment->due_date);

        return [(int) $due->month, (int) $due->year];
    }
}
