<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Support\BusinessDay;
use App\Support\LoanFundingStrategy;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Reverses fund + loan exposure for a source loan during admin Full transfer.
 * Does not claw back cash already paid out to the borrower.
 */
final class LoanTransferUnwindService
{
    public function __construct(
        private AccountingService $accounting,
        private LoanLedgerService $ledger,
    ) {}

    /**
     * @return array{
     *     loan_account_balance: float,
     *     member_fund_net_change: float,
     *     fund_restore_amount: float,
     *     direct_master_reverse_amount: float
     * }
     */
    public function computeNets(Loan $loan): array
    {
        $loan->loadMissing(['member.accounts', 'installments']);
        $member = $loan->member;

        if ($member === null) {
            throw new InvalidArgumentException(__('Loan has no borrower.'));
        }

        $this->ledger->ensureMemberAccounts($member);
        $loanAccount = $this->ledger->ensureLoanAccount($loan);
        $memberFund = $member->fresh()->fundAccount;

        if ($memberFund === null) {
            throw new RuntimeException(__('Member fund account is missing.'));
        }

        $loanAccountBalance = round((float) $loanAccount->fresh()->balance, 2);
        $memberFundNetChange = $this->memberFundNetChangeForLoan($loan, $memberFund);
        $fundRestoreAmount = LoanTransferPreview::fundRestoreAmount($memberFundNetChange);
        $directMasterReverseAmount = $this->directMasterFundDebitTotal($loan);

        return [
            'loan_account_balance' => $loanAccountBalance,
            'member_fund_net_change' => $memberFundNetChange,
            'fund_restore_amount' => $fundRestoreAmount,
            'direct_master_reverse_amount' => $directMasterReverseAmount,
        ];
    }

    public function unwind(Loan $loan): void
    {
        $loan->loadMissing(['member.accounts', 'installments']);
        $member = $loan->member;

        if ($member === null) {
            throw new InvalidArgumentException(__('Loan has no borrower.'));
        }

        $nets = $this->computeNets($loan);
        $this->ledger->ensureMemberAccounts($member);
        $loanAccount = $this->ledger->ensureLoanAccount($loan);
        $memberFund = $member->fresh()->fundAccount;
        $masterFund = Account::masterFund();

        if ($memberFund === null || $masterFund === null) {
            throw new RuntimeException(__('Required fund accounts are not configured.'));
        }

        $at = BusinessDay::now();
        $label = __('Loan #:id admin transfer full unwind – :name', [
            'id' => $loan->id,
            'name' => $member->name,
        ]);

        DB::transaction(function () use ($loan, $member, $loanAccount, $memberFund, $masterFund, $nets, $label, $at): void {
            $loanBalance = $nets['loan_account_balance'];

            if ($loanBalance > 0.01) {
                $this->accounting->credit(
                    $loanAccount,
                    $loanBalance,
                    $label.' '.__('(clear loan principal)'),
                    $loan,
                    $at,
                    $member->id,
                );
            } elseif ($loanBalance < -0.01) {
                $this->accounting->debit(
                    $loanAccount,
                    abs($loanBalance),
                    $label.' '.__('(clear loan principal)'),
                    $loan,
                    $at,
                    $member->id,
                );
            }

            $fundRestore = $nets['fund_restore_amount'];

            if ($fundRestore > 0.01) {
                $this->accounting->creditMemberFundWithMasterMirror(
                    $memberFund,
                    $fundRestore,
                    $label,
                    __('(fund restore mirror)'),
                    $loan,
                    $at,
                    $member->id,
                );
            } elseif ($fundRestore < -0.01) {
                $this->accounting->debitMemberFundWithMasterMirror(
                    $memberFund,
                    abs($fundRestore),
                    $label,
                    __('(fund restore mirror)'),
                    $loan,
                    $at,
                    $member->id,
                );
            }

            $directMaster = $nets['direct_master_reverse_amount'];

            if ($directMaster > 0.01) {
                $this->accounting->credit(
                    $masterFund,
                    $directMaster,
                    $label.' '.__('(reverse master fund share)'),
                    $loan,
                    $at,
                    $member->id,
                );
            }

            $loan->installments()
                ->whereIn('status', ['pending', 'overdue'])
                ->forceDelete();

            $loan->update([
                'status' => 'cancelled',
                'lifecycle_stage' => 'cancelled',
                'cancellation_reason' => 'ADMIN_TRANSFER_FULL_UNWIND',
                'cancelled_at' => $at,
                'repaid_to_master' => (float) ($loan->master_portion ?? 0),
            ]);
        });
    }

    public function memberFundNetChangeForLoan(Loan $loan, Account $memberFund): float
    {
        $credits = 0.0;
        $debits = 0.0;

        foreach ($this->loanRelatedTransactions($loan, $memberFund->id) as $transaction) {
            $amount = (float) $transaction->amount;

            if ($transaction->type === 'credit') {
                $credits += $amount;
            } else {
                $debits += $amount;
            }
        }

        return round($credits - $debits, 2);
    }

    /**
     * Direct master-fund debits for this loan's disbursement share (non-split path),
     * excluding mirrored legs from member-fund postings.
     */
    public function directMasterFundDebitTotal(Loan $loan): float
    {
        $masterFund = Account::masterFund();

        if ($masterFund === null) {
            return 0.0;
        }

        $strategy = LoanFundingStrategy::normalize($loan->funding_strategy);

        if (LoanFundingStrategy::usesConfiguredSplit($strategy)) {
            // Split takes master via member-fund mirror; reversing member fund restores master.
            return 0.0;
        }

        $marker = '(master fund share)';
        $arabicMarker = 'حصة صندوق الماستر';

        $total = Transaction::query()
            ->where('account_id', $masterFund->id)
            ->where('type', 'debit')
            ->where('reference_type', $loan->getMorphClass())
            ->where('reference_id', $loan->id)
            ->where(function ($query) use ($marker, $arabicMarker): void {
                $query->where('description', 'like', '%'.$marker.'%')
                    ->orWhere('description', 'like', '%'.$arabicMarker.'%');
            })
            ->sum('amount');

        return round((float) $total, 2);
    }

    /**
     * @return list<Transaction>
     */
    private function loanRelatedTransactions(Loan $loan, int $accountId): array
    {
        $installmentIds = $loan->installments()->pluck('id')->all();
        $loanMorph = $loan->getMorphClass();
        $installmentMorph = (new LoanInstallment)->getMorphClass();

        return Transaction::query()
            ->where('account_id', $accountId)
            ->where(function ($query) use ($loan, $loanMorph, $installmentMorph, $installmentIds): void {
                $query->where(function ($inner) use ($loan, $loanMorph): void {
                    $inner->where('reference_type', $loanMorph)
                        ->where('reference_id', $loan->id);
                });

                if ($installmentIds !== []) {
                    $query->orWhere(function ($inner) use ($installmentMorph, $installmentIds): void {
                        $inner->where('reference_type', $installmentMorph)
                            ->whereIn('reference_id', $installmentIds);
                    });
                }
            })
            ->get()
            ->all();
    }
}
