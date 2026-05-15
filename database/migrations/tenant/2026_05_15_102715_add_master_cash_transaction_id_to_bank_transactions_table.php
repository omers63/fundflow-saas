<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Transaction;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->foreignId('master_cash_transaction_id')
                ->nullable()
                ->after('fund_posting_id')
                ->constrained('transactions')
                ->nullOnDelete();
        });

        $masterCashAccountId = Account::query()
            ->where('is_master', true)
            ->where('type', 'cash')
            ->value('id');

        if ($masterCashAccountId === null) {
            return;
        }

        BankTransaction::query()
            ->whereIn('status', ['mirrored', 'posted'])
            ->whereNull('master_cash_transaction_id')
            ->eachById(function (BankTransaction $bankTransaction) use ($masterCashAccountId): void {
                $ledgerTransaction = Transaction::query()
                    ->where('reference_type', BankTransaction::class)
                    ->where('reference_id', $bankTransaction->id)
                    ->where('account_id', $masterCashAccountId)
                    ->orderBy('id')
                    ->first();

                if ($ledgerTransaction === null) {
                    return;
                }

                $bankTransaction->update([
                    'master_cash_transaction_id' => $ledgerTransaction->id,
                ]);
            });
    }

    public function down(): void
    {
        Schema::table('bank_transactions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('master_cash_transaction_id');
        });
    }
};
