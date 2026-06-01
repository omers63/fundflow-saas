<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanDisbursement;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
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

        DB::transaction(function () use ($loan, $member, $memberFund, $masterFund, $memberCash, $loanAccount, $amount, $disbursementRecord, $disbursedAt, $allowNegativeMasterFundBalance): void {
            if (
                ! $allowNegativeMasterFundBalance
                && $amount > 0
                && (float) $masterFund->fresh()->balance < $amount
            ) {
                throw new RuntimeException(__('Insufficient master fund balance.'));
            }

            $seq = $loan->disbursements()->count();
            $label = __('Loan #:id disbursement (#:seq) – :name', [
                'id' => $loan->id,
                'seq' => $seq,
                'name' => $member->name,
            ]);
            $at = $disbursedAt ?? now();

            $this->accounting->debit($loanAccount, $amount, $label, $loan, $at, $member->id);
            $this->accounting->debitMemberFundWithMasterMirror(
                $memberFund,
                $amount,
                $label,
                __('(master funded)'),
                $loan,
                $at,
                $member->id,
            );
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
                'member_portion' => 0,
                'master_portion' => $amount,
            ]);

            $loan->increment('amount_disbursed', $amount);
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

        $this->accounting->credit($loanAccount, $memberPortion, $description, $loan, $postedAt ?? now(), $loan->member_id);
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
            $transactedAt ?? now(),
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
            now(),
            $guarantor->id,
        );
    }

    private function postLoanPrincipalRepayment(
        Loan $loan,
        float $amount,
        string $description,
        Model $source,
        int $memberId,
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
            null,
            $memberId,
        );
        $this->accounting->credit($loanAccount, $amount, $description, $source, null, $memberId);

        if ($repaidSlice > 0.00001) {
            $loan->increment('repaid_to_master', $repaidSlice);
        }

        $loan->refresh();
        $loan->releaseGuarantorIfDue();
    }
}
