<?php

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class AccountingService
{
    /**
     * Transfer money between two accounts with transaction logging.
     *
     * @param  Account  $from  Source account (debited)
     * @param  Account  $to  Destination account (credited)
     * @param  float  $amount  Amount to transfer
     * @param  string  $description  Human-readable description
     * @param  Model|null  $reference  Polymorphic reference (Contribution, Loan, LoanRepayment)
     */
    public function transfer(
        Account $from,
        Account $to,
        float $amount,
        string $description,
        ?Model $reference = null,
    ): void {
        DB::transaction(function () use ($from, $to, $amount, $description, $reference) {
            $this->debit($from, $amount, $description, $reference);
            $this->credit($to, $amount, $description, $reference);
        });
    }

    /**
     * Credit an account (increase balance).
     */
    public function credit(
        Account $account,
        float $amount,
        string $description,
        ?Model $reference = null,
    ): Transaction {
        return DB::transaction(function () use ($account, $amount, $description, $reference) {
            $account->lockForUpdate();
            $account->refresh();

            $newBalance = (float) $account->balance + $amount;
            $account->update(['balance' => $newBalance]);

            return Transaction::create([
                'account_id' => $account->id,
                'type' => 'credit',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference?->id,
                'description' => $description,
                'transacted_at' => now(),
            ]);
        });
    }

    /**
     * Debit an account (decrease balance).
     */
    public function debit(
        Account $account,
        float $amount,
        string $description,
        ?Model $reference = null,
    ): Transaction {
        return DB::transaction(function () use ($account, $amount, $description, $reference) {
            $account->lockForUpdate();
            $account->refresh();

            $newBalance = (float) $account->balance - $amount;
            $account->update(['balance' => $newBalance]);

            return Transaction::create([
                'account_id' => $account->id,
                'type' => 'debit',
                'amount' => $amount,
                'balance_after' => $newBalance,
                'reference_type' => $reference ? get_class($reference) : null,
                'reference_id' => $reference?->id,
                'description' => $description,
                'transacted_at' => now(),
            ]);
        });
    }

    /**
     * Mirror a transaction to an account (credit or debit based on amount sign)
     * without affecting the source account. Used when posting bank transactions
     * to master cash, or mirroring to master fund.
     */
    public function mirror(
        Account $account,
        float $amount,
        string $description,
        ?Model $reference = null,
    ): Transaction {
        if ($amount >= 0) {
            return $this->credit($account, $amount, $description, $reference);
        }

        return $this->debit($account, abs($amount), $description, $reference);
    }

    /**
     * Create the two member accounts (cash + fund) when a member is created.
     */
    public function createMemberAccounts(Member $member): void
    {
        Account::create([
            'member_id' => $member->id,
            'type' => 'cash',
            'name' => $member->name.' - Cash',
            'balance' => 0,
            'is_master' => false,
        ]);

        Account::create([
            'member_id' => $member->id,
            'type' => 'fund',
            'name' => $member->name.' - Fund',
            'balance' => 0,
            'is_master' => false,
        ]);
    }
}
