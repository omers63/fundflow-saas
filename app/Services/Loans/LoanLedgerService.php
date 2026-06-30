<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanDisbursement;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Support\BusinessDay;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Loan-specific ledger postings adapted from legacy FundFlow accounting.
 */
final class LoanLedgerService
{
    public function __construct(
        private AccountingService $accounting,
    ) {}

    public static function excessFundToCashDescriptionMarker(): string
    {
        return 'excess fund to cash';
    }

    public function ensureMemberAccounts(Member $member): void
    {
        $this->accounting->createMemberAccounts($member);
    }

    public function ensureLoanAccount(Loan $loan): Account
    {
        $loan->loadMissing('member');
        $member = $loan->member;

        return Account::query()->firstOrCreate(
            [
                'type' => Account::TYPE_LOAN,
                'loan_id' => $loan->id,
            ],
            [
                'member_id' => $member->id,
                'name' => __('Loan #:id – :name', ['id' => $loan->id, 'name' => $member->name]),
                'balance' => 0,
                'is_master' => false,
            ],
        );
    }

    public function postPartialLoanDisbursement(
        Loan $loan,
        float $amount,
        LoanDisbursement $disbursementRecord,
        ?CarbonInterface $disbursedAt = null,
        bool $allowNegativeMasterFundBalance = false,
        ?float $memberFundBalanceAtDisbursement = null,
    ): void {
        $member = $loan->member;
        $this->ensureMemberAccounts($member);
        $loanAccount = $this->ensureLoanAccount($loan);

        $memberFund = $member->fundAccount;
        $masterFund = Account::masterFund();
        $masterCash = Account::masterCash();
        $memberCash = $member->cashAccount;

        if ($memberFund === null || $masterFund === null || $masterCash === null || $memberCash === null) {
            throw new RuntimeException(__('Required accounts are not configured.'));
        }

        $approved = (float) ($loan->amount_approved ?: $loan->amount);
        $fundBal = $memberFundBalanceAtDisbursement ?? (float) $memberFund->balance;
        $strategy = LoanFundingStrategy::normalize($loan->funding_strategy);
        $portions = LoanSettings::resolveFundingPortions($approved, $fundBal, $strategy);
        $ratio = $approved > 0 ? $amount / $approved : 1.0;
        $memberFundDebit = round($portions['member_portion'] * $ratio, 2);
        $masterFundDebit = round($portions['master_portion'] * $ratio, 2);
        $fullMasterPortion = round($portions['master_portion'], 2);
        $isSplitStrategy = $strategy === LoanFundingStrategy::SPLIT_PERCENTAGE;
        $fundBalForMemberPortionCheck = $memberFundBalanceAtDisbursement ?? (float) $memberFund->balance;
        $willCompleteDisbursement = ((float) $loan->amount_disbursed + $amount) >= $approved - 0.01;

        if (
            $isSplitStrategy
            && $willCompleteDisbursement
            && ! $allowNegativeMasterFundBalance
            && ($memberFundDebit + $fullMasterPortion) > (float) $masterFund->balance + 0.01
        ) {
            throw new RuntimeException(__('Insufficient master fund balance.'));
        }

        DB::transaction(function () use ($loan, $member, $memberFund, $masterFund, $memberCash, $loanAccount, $amount, $memberFundDebit, $masterFundDebit, $fullMasterPortion, $disbursementRecord, $disbursedAt, $allowNegativeMasterFundBalance, $isSplitStrategy, $fundBalForMemberPortionCheck, $approved, $strategy, $memberFundBalanceAtDisbursement, $willCompleteDisbursement): void {
            if (
                ! $isSplitStrategy
                && ! $allowNegativeMasterFundBalance
                && $masterFundDebit > 0
                && (float) $masterFund->fresh()->balance < $masterFundDebit
            ) {
                throw new RuntimeException(__('Insufficient master fund balance.'));
            }

            if ($memberFundDebit > $fundBalForMemberPortionCheck + 0.01) {
                throw new RuntimeException(__('Insufficient member fund balance for disbursement.'));
            }

            $seq = $loan->disbursements()->count();
            $label = __('Loan #:id disbursement (#:seq) – :name', [
                'id' => $loan->id,
                'seq' => $seq,
                'name' => $member->name,
            ]);
            $at = $disbursedAt ?? BusinessDay::now();

            $this->accounting->debit($loanAccount, $amount, $label, $loan, $at, $member->id);

            if ($memberFundDebit > 0.00001) {
                $this->accounting->debitMemberFundWithMasterMirror(
                    $memberFund,
                    $memberFundDebit,
                    $label,
                    __('(member fund share)'),
                    $loan,
                    $at,
                    $member->id,
                );
            }

            if (! $isSplitStrategy && $masterFundDebit > 0.00001) {
                $this->accounting->debit(
                    $masterFund,
                    $masterFundDebit,
                    $label.' '.__('(master fund share)'),
                    $loan,
                    $at,
                    $member->id,
                );
            }

            $this->accounting->creditMemberCashWithMasterMirror(
                $memberCash,
                $amount,
                $label,
                __('(cash payout mirror)'),
                $loan,
                $at,
                $member->id,
            );

            $disbursementRecord->update([
                'member_portion' => $memberFundDebit,
                'master_portion' => $masterFundDebit,
            ]);

            $loan->increment('amount_disbursed', $amount);

            if (! $isSplitStrategy || ! $willCompleteDisbursement) {
                return;
            }

            if ($loan->cash_out_excess_fund) {
                $excess = LoanSettings::excessFundCashOutAmount(
                    $approved,
                    $memberFundBalanceAtDisbursement ?? (float) $memberFund->fresh()->balance,
                    $strategy,
                );

                if ($excess > 0.00001) {
                    $this->transferMemberFundBalanceToCash($loan, $excess, $at);
                    $memberFund->refresh();
                }
            }

            if ($fullMasterPortion > 0.00001) {
                $this->accounting->debitMemberFundWithMasterMirror(
                    $memberFund,
                    $fullMasterPortion,
                    $label,
                    __('(master fund share)'),
                    $loan,
                    $at,
                    $member->id,
                );
            }
        });
    }

    public function transferMemberFundBalanceToCash(
        Loan $loan,
        float $amount,
        ?CarbonInterface $transactedAt = null,
        bool $allowNegativeMemberFundBalance = false,
    ): void {
        if ($amount <= 0.00001) {
            return;
        }

        $loan->loadMissing('member');
        $member = $loan->member;
        $this->ensureMemberAccounts($member);

        $memberFund = $member->fundAccount;
        $memberCash = $member->cashAccount;

        if ($memberFund === null || $memberCash === null) {
            throw new RuntimeException(__('Member fund and cash accounts are required.'));
        }

        if (
            ! $allowNegativeMemberFundBalance
            && $member->getFundBalance() < $amount - 0.00001
        ) {
            throw new RuntimeException(__('Insufficient fund balance for the requested transfer.'));
        }

        $description = __('Loan #:id — excess fund to cash', ['id' => $loan->id]);
        $at = $transactedAt ?? BusinessDay::now();

        DB::transaction(function () use ($member, $memberFund, $memberCash, $amount, $description, $loan, $at): void {
            $this->accounting->debitMemberFundWithMasterMirror(
                $memberFund,
                $amount,
                $description,
                __('(master fund mirror)'),
                $loan,
                $at,
                $member->id,
            );
            $this->accounting->creditMemberCashWithMasterMirror(
                $memberCash,
                $amount,
                $description,
                __('(master cash mirror)'),
                $loan,
                $at,
                $member->id,
            );
        });
    }

    public function recognizeMemberPortionAgainstLoanPrincipal(Loan $loan, ?CarbonInterface $postedAt = null): void
    {
        $memberPortion = (float) ($loan->member_portion ?? 0);
        if ($memberPortion <= 0.00001) {
            return;
        }

        $loanAccount = $this->ensureLoanAccount($loan);
        $marker = __('(member portion applied to principal)');
        $exists = $loanAccount->transactions()
            ->where('reference_type', $loan->getMorphClass())
            ->where('reference_id', $loan->getKey())
            ->where('type', 'credit')
            ->where('description', 'like', '%'.$marker.'%')
            ->exists();

        if ($exists) {
            return;
        }

        $description = __('Loan #:id – :name :marker', [
            'id' => $loan->id,
            'name' => $loan->member->name,
            'marker' => $marker,
        ]);

        $this->accounting->credit($loanAccount, $memberPortion, $description, $loan, $postedAt ?? BusinessDay::now(), $loan->member_id);
    }

    public static function principalAmountCreditingMasterRepaidSlice(
        float $masterPortion,
        float $repaidToMasterBefore,
        float $principalAmount,
    ): float {
        if ($principalAmount <= 0.00001) {
            return 0.0;
        }

        $remainingMaster = max(0.0, round($masterPortion, 2) - round($repaidToMasterBefore, 2));

        return min(round($principalAmount, 2), $remainingMaster);
    }

    public function postLoanRepayment(LoanInstallment $installment): void
    {
        $loan = $installment->loan;
        $member = $loan->member;
        $this->ensureMemberAccounts($member);

        $amount = (float) $installment->amount;
        $lateFee = (float) ($installment->late_fee_amount ?? 0);
        $description = __('Loan #:id repayment (installment #:num) – :name', [
            'id' => $loan->id,
            'num' => $installment->installment_number,
            'name' => $member->name,
        ]);

        DB::transaction(function () use ($installment, $loan, $member, $amount, $description): void {
            $this->postLoanPrincipalRepayment($loan, $amount, $description, $installment, $member->id);
        });
    }

    public function debitCashForRepayment(
        Member $member,
        LoanInstallment $installment,
        float $lateFee = 0.0,
        ?CarbonInterface $transactedAt = null,
        ?float $principalAmount = null,
    ): void {
        $cash = $member->cashAccount;
        if ($cash === null) {
            throw new RuntimeException(__('Member cash account is missing.'));
        }

        $principal = $principalAmount ?? (float) $installment->amount;
        $total = $principal + $lateFee;
        $description = __('Loan #:id installment #:num', [
            'id' => $installment->loan_id,
            'num' => $installment->installment_number,
        ]);

        $this->accounting->debitMemberCashWithMasterMirror(
            $cash,
            $total,
            $description,
            __('(loan repayment mirror)'),
            $installment,
            $transactedAt ?? BusinessDay::now(),
            $member->id,
        );
    }

    public function debitGuarantorFundForDefault(Member $guarantor, LoanInstallment $installment): void
    {
        $fund = $guarantor->fundAccount;
        if ($fund === null) {
            throw new RuntimeException(__('Guarantor fund account is missing.'));
        }

        $amount = (float) $installment->amount + (float) ($installment->late_fee_amount ?? 0);
        $description = __('Guarantor default – loan #:id installment #:num', [
            'id' => $installment->loan_id,
            'num' => $installment->installment_number,
        ]);

        $this->accounting->debitMemberFundWithMasterMirror(
            $fund,
            $amount,
            $description,
            __('(guarantor default mirror)'),
            $installment,
            BusinessDay::now(),
            $guarantor->id,
        );
    }

    private function postLoanPrincipalRepayment(
        Loan $loan,
        float $amount,
        string $description,
        Model $source,
        int $memberId,
        ?CarbonInterface $transactedAt = null,
    ): void {
        $masterFund = Account::masterFund();
        $memberFund = $loan->member->fundAccount;
        $loanAccount = $this->ensureLoanAccount($loan);

        if ($masterFund === null || $memberFund === null) {
            throw new RuntimeException(__('Fund accounts are not configured.'));
        }

        $repaidSlice = self::principalAmountCreditingMasterRepaidSlice(
            (float) $loan->master_portion,
            (float) $loan->repaid_to_master,
            $amount,
        );

        $this->accounting->creditMemberFundWithMasterMirror(
            $memberFund,
            $amount,
            $description,
            __('(loan repayment mirror)'),
            $source,
            $transactedAt,
            $memberId,
        );
        $this->accounting->credit($loanAccount, $amount, $description, $source, $transactedAt, $memberId);

        if ($repaidSlice > 0.00001) {
            $loan->increment('repaid_to_master', $repaidSlice);
        }

        $loan->refresh();
        $loan->releaseGuarantorIfDue();
    }

    /**
     * Disburse an imported loan using explicit member/master portions from CSV (historical migration).
     */
    public function postImportedLoanDisbursementWithPortions(
        Loan $loan,
        float $memberPortion,
        float $masterPortion,
        ?CarbonInterface $disbursedAt = null,
        bool $allowNegativeMasterFundBalance = false,
    ): LoanDisbursement {
        $totalAmount = round((float) $loan->amount_approved, 2);
        $sum = round($memberPortion + $masterPortion, 2);

        if (abs($sum - $totalAmount) > 0.02) {
            throw new RuntimeException(
                __('member_portion + master_portion must equal amount_approved (within 0.02).')
            );
        }

        if ($memberPortion < -0.02 || $masterPortion < -0.02) {
            throw new RuntimeException(__('Portions cannot be negative.'));
        }

        $member = $loan->member;
        $this->ensureMemberAccounts($member);
        $loanAccount = $this->ensureLoanAccount($loan);

        $memberFund = $member->fundAccount;
        $masterFund = Account::masterFund();
        $memberCash = $member->cashAccount;

        if ($memberFund === null || $masterFund === null || $memberCash === null) {
            throw new RuntimeException(__('Required accounts are not configured.'));
        }

        $at = $disbursedAt ?? BusinessDay::now();
        $label = __('Loan #:id disbursement (import) – :name', [
            'id' => $loan->id,
            'name' => $member->name,
        ]);

        $disbursement = LoanDisbursement::create([
            'loan_id' => $loan->id,
            'amount' => $totalAmount,
            'member_portion' => $memberPortion,
            'master_portion' => $masterPortion,
            'disbursed_at' => $at,
            'disbursed_by_id' => auth('tenant')->id(),
            'notes' => __('CSV import'),
        ]);

        DB::transaction(function () use ($loan, $member, $memberFund, $masterFund, $memberCash, $loanAccount, $label, $totalAmount, $memberPortion, $masterPortion, $at, $allowNegativeMasterFundBalance, $disbursement): void {
            $masterFundLocked = Account::query()->lockForUpdate()->findOrFail($masterFund->id);

            if (
                ! $allowNegativeMasterFundBalance
                && $masterPortion > 0.00001
                && (float) $masterFundLocked->balance < $masterPortion
            ) {
                throw new RuntimeException(__('Insufficient master fund balance.'));
            }

            $this->accounting->debit($loanAccount, $totalAmount, $label, $loan, $at, $member->id);

            if ($memberPortion > 0.00001) {
                $principalLabel = $label.' '.__('(member portion applied to principal)');
                $this->accounting->credit($loanAccount, $memberPortion, $principalLabel, $loan, $at, $member->id);
                $this->accounting->debitMemberFundWithMasterMirror(
                    $memberFund,
                    $memberPortion,
                    $label,
                    __('(member fund share)'),
                    $loan,
                    $at,
                    $member->id,
                );
            }

            if ($masterPortion > 0.00001) {
                $this->accounting->debitMemberFundWithMasterMirror(
                    $memberFund,
                    $masterPortion,
                    $label,
                    __('(master fund share)'),
                    $loan,
                    $at,
                    $member->id,
                );
            }

            AccountingService::withoutMemberCashCollection(function () use ($memberCash, $totalAmount, $label, $loan, $at, $member): void {
                $this->accounting->creditMemberCashWithMasterMirror(
                    $memberCash,
                    $totalAmount,
                    $label,
                    __('(cash payout mirror)'),
                    $loan,
                    $at,
                    $member->id,
                );
            });

            $loan->update([
                'member_portion' => $memberPortion,
                'master_portion' => $masterPortion,
                'amount_disbursed' => $totalAmount,
            ]);

            $disbursement->update([
                'member_portion' => $memberPortion,
                'master_portion' => $masterPortion,
            ]);
        });

        return $disbursement->fresh();
    }

    /**
     * Apply cumulative repayments already collected before go-live (CSV import).
     */
    public function postImportedLoanRepayments(Loan $loan, float $totalRepaid): void
    {
        if ($totalRepaid <= 0.00001) {
            return;
        }

        $member = $loan->member;
        $this->ensureMemberAccounts($member);

        $description = __('Loan #:id repayments (import, bulk) – :name', [
            'id' => $loan->id,
            'name' => $member->name,
        ]);

        DB::transaction(function () use ($loan, $member, $description, $totalRepaid): void {
            Loan::query()->whereKey($loan->getKey())->lockForUpdate()->firstOrFail();

            $this->postLoanPrincipalRepayment($loan, $totalRepaid, $description, $loan, $member->id);
        });
    }

    public function postImportedLoanRepaymentWithCashFlow(
        Loan $loan,
        LoanRepayment $repayment,
        float $amount,
        ?CarbonInterface $paidAt = null,
    ): void {
        if ($amount <= 0.00001) {
            return;
        }

        $member = $loan->member;
        $this->ensureMemberAccounts($member);
        $cash = $member->cashAccount;

        if ($cash === null) {
            throw new RuntimeException(__('Member cash account is missing.'));
        }

        $at = $paidAt ?? BusinessDay::now();
        $description = __('Loan #:id repayments (import, bulk) – :name', [
            'id' => $loan->id,
            'name' => $member->name,
        ]);

        DB::transaction(function () use ($loan, $member, $cash, $amount, $description, $repayment, $at): void {
            Loan::query()->whereKey($loan->getKey())->lockForUpdate()->firstOrFail();

            $lockedCash = Account::query()->lockForUpdate()->findOrFail($cash->id);
            $lockedCash->refresh();

            AccountingService::withoutMemberCashCollection(function () use ($lockedCash, $amount, $description, $repayment, $at, $member): void {
                $this->accounting->creditMemberCashWithMasterMirror(
                    $lockedCash,
                    $amount,
                    $description,
                    __('(loan repayment cash-in mirror)'),
                    $repayment,
                    $at,
                    $member->id,
                );

                $this->accounting->debitMemberCashWithMasterMirror(
                    $lockedCash,
                    $amount,
                    $description,
                    __('(loan repayment mirror)'),
                    $repayment,
                    $at,
                    $member->id,
                );
            });

            $this->postLoanPrincipalRepayment($loan, $amount, $description, $repayment, $member->id, $at);
        });
    }
}
