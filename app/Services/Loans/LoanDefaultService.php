<?php

namespace App\Services\Loans;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Notifications\Tenant\LoanDefaultBorrowerGuarantorPaidNotification;
use App\Notifications\Tenant\LoanDefaultGuarantorNotification;
use App\Notifications\Tenant\LoanDefaultWarningNotification;
use App\Notifications\Tenant\LoanSettledNotification;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Support\BusinessDay;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;

class LoanDefaultService
{
    public function __construct(
        protected LoanLedgerService $ledger,
        protected ContributionCycleService $cycles,
        protected LateFeeService $lateFees,
    ) {}

    /**
     * For each active loan with overdue installments:
     *  - Within grace (cumulative late + overdue count) → warn borrower only.
     *  - Past grace, guarantor liability transferred, or guarantor already paid
     *    any installment → debit the guarantor's fund for every remaining overdue
     *    installment (not only the installment that crossed the threshold).
     *
     * @return array{
     *     warned: int,
     *     debited_from_guarantor: int,
     *     warned_loan_ids: list<int>,
     *     debited_loan_ids: list<int>
     * }
     */
    public function processDefaults(): array
    {
        $grace = Setting::loanDefaultGraceCycles();
        $warned = 0;
        $debited = 0;
        $warnedLoanIds = [];
        $debitedLoanIds = [];

        Loan::active()
            ->with(['member.user', 'guarantor.user', 'installments'])
            ->each(function (Loan $loan) use ($grace, &$warned, &$debited, &$warnedLoanIds, &$debitedLoanIds) {
                $overdueInstallments = $loan->installments()
                    ->where('status', 'overdue')
                    ->where('paid_by_guarantor', false)
                    ->orderBy('due_date')
                    ->get();

                if ($overdueInstallments->isEmpty()) {
                    return;
                }

                $projectedDefaults = (int) $loan->late_repayment_count + $overdueInstallments->count();
                $guarantorLiabilityFlag = $loan->guarantor_liability_transferred_at !== null;
                // Once the guarantor fund has already paid any installment, keep collecting
                // remaining overdues — do not fall back into grace after a partial run.
                $guarantorAlreadyCollecting = $loan->installments->contains(
                    fn (LoanInstallment $installment): bool => (bool) $installment->paid_by_guarantor
                );
                $shouldDebitGuarantor = $guarantorLiabilityFlag
                    || $guarantorAlreadyCollecting
                    || $projectedDefaults > $grace;
                $canDebitGuarantor = $loan->guarantor_member_id
                    && ! $loan->isGuarantorReleased()
                    && $loan->guarantor !== null;

                if ($shouldDebitGuarantor && $canDebitGuarantor) {
                    $errorMessage = $guarantorLiabilityFlag
                        ? 'LoanDefaultService: guarantor debit failed (delinquency liability)'
                        : 'LoanDefaultService: guarantor debit failed';

                    $loanDebited = false;

                    foreach ($overdueInstallments as $installment) {
                        if ($this->debitGuarantorForInstallment($loan, $installment, $errorMessage)) {
                            $debited++;
                            $loanDebited = true;
                        }
                    }

                    if ($loanDebited) {
                        $debitedLoanIds[(int) $loan->id] = true;
                    }

                    return;
                }

                // Still within grace, or no guarantor available to debit: warn only.
                $totalDefaults = (int) $loan->late_repayment_count;
                $loanWarned = false;

                foreach ($overdueInstallments as $installment) {
                    $totalDefaults++;

                    if ($this->warnBorrower($loan, $installment, $totalDefaults, $grace)) {
                        $warned++;
                        $loanWarned = true;
                    }
                }

                if ($loanWarned) {
                    $warnedLoanIds[(int) $loan->id] = true;
                }
            });

        $warnedIds = array_keys($warnedLoanIds);
        $debitedIds = array_keys($debitedLoanIds);
        sort($warnedIds);
        sort($debitedIds);

        return [
            'warned' => $warned,
            'debited_from_guarantor' => $debited,
            'warned_loan_ids' => $warnedIds,
            'debited_loan_ids' => $debitedIds,
        ];
    }

    /**
     * Check all active loans and mark them settled if both conditions are met:
     * 1. repaid_to_master >= master_portion
     * 2. member fund account >= settlement_threshold * amount_approved
     *
     * Returns count of loans settled.
     */
    public function checkSettlements(): int
    {
        $settled = 0;

        Loan::active()->with(['member.user', 'member.accounts'])->each(function (Loan $loan) use (&$settled) {
            if ($loan->isReadyToSettle()) {
                $loan->update([
                    'status' => 'completed',
                    'settled_at' => BusinessDay::now(),
                ]);

                $this->notifyUserIfPresent($loan->member->user, new LoanSettledNotification($loan));

                $settled++;
            }
        });

        return $settled;
    }

    private function warnBorrower(Loan $loan, LoanInstallment $installment, int $totalDefaults, int $grace): bool
    {
        $user = $loan->member->user;

        if ($user === null) {
            return false;
        }

        try {
            $user->notify(
                new LoanDefaultWarningNotification($loan, $installment, $totalDefaults, $grace)
            );

            return true;
        } catch (\Throwable $e) {
            logger()->error('LoanDefaultService: warning notification failed', ['loan_id' => $loan->id]);

            return false;
        }
    }

    private function debitGuarantorForInstallment(Loan $loan, LoanInstallment $installment, string $errorMessage): bool
    {
        $guarantorCovered = false;

        try {
            DB::transaction(function () use ($loan, $installment, &$guarantorCovered): void {
                $due = $installment->due_date;
                $deadline = $this->cycles->deadline((int) $due->month, (int) $due->year);
                $days = $this->lateFees->daysPastDue($deadline, BusinessDay::now());
                $feeAmount = $this->lateFees->repaymentLateFeeForDays($days);

                $isLate = $days >= 1;
                $principalOutstanding = max(
                    0.0,
                    (float) $installment->amount - (float) ($installment->amount_collected ?? 0),
                );
                $requiredCash = $principalOutstanding + $feeAmount;
                $borrower = $loan->member;
                $guarantor = $loan->guarantor;

                if ($borrower === null || $guarantor === null) {
                    throw new \RuntimeException(__('Borrower or guarantor is missing.'));
                }

                $borrowerCash = (float) $borrower->fresh()->getCashBalance();
                $guarantorTopUp = max(0.0, $requiredCash - $borrowerCash);
                $guarantorCovered = $guarantorTopUp > 0.00001;

                AccountingService::withoutMemberCashCollection(function () use ($installment, $borrower, $guarantor, $principalOutstanding, $feeAmount, $guarantorTopUp, $guarantorCovered, $isLate): void {
                    if ($guarantorTopUp > 0.00001) {
                        $this->ledger->topUpBorrowerCashFromGuarantorFund(
                            $guarantor,
                            $borrower,
                            $installment,
                            $guarantorTopUp,
                        );
                    }

                    $this->ledger->debitCashForRepayment(
                        $borrower,
                        $installment,
                        $feeAmount,
                        null,
                        $principalOutstanding,
                    );

                    $installment->update([
                        'status' => 'paid',
                        'paid_at' => BusinessDay::now(),
                        'paid_by_guarantor' => $guarantorCovered,
                        'is_late' => $isLate,
                        'late_fee_amount' => $feeAmount > 0.00001 ? $feeAmount : 0,
                        'amount_collected' => (float) $installment->amount,
                    ]);
                });

                if ($isLate) {
                    $amount = (float) $installment->amount;
                    $loan->increment('late_repayment_count');
                    $loan->increment('late_repayment_amount', $amount);
                    $loan->member?->increment('late_repayment_count');
                    $loan->member?->increment('late_repayment_amount', $amount);
                }

                $loan->releaseGuarantorIfDue();
            });
        } catch (\Throwable $e) {
            logger()->error($errorMessage, [
                'loan_id' => $loan->id,
                'installment' => $installment->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }

        if ($guarantorCovered) {
            $this->notifyUserIfPresent(
                $loan->guarantor?->user,
                new LoanDefaultGuarantorNotification($loan, $installment)
            );
        }

        $loan->loadMissing(['member.user', 'guarantor']);

        $borrowerUser = $loan->member?->user;
        $guarantorUserId = $loan->guarantor?->user?->id;

        // Avoid a duplicate alert when the borrower and guarantor share the same login.
        if ($guarantorCovered && $borrowerUser !== null && $borrowerUser->id !== $guarantorUserId) {
            $this->notifyUserIfPresent(
                $borrowerUser,
                new LoanDefaultBorrowerGuarantorPaidNotification($loan, $installment)
            );
        }

        return $guarantorCovered;
    }

    private function notifyUserIfPresent(?User $user, Notification $notification): void
    {
        if ($user === null) {
            return;
        }

        try {
            $user->notify($notification);
        } catch (\Throwable) {
            // best-effort
        }
    }
}
