<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Transaction::query()->delete();
    BankTransaction::query()->delete();
    BankStatement::query()->delete();
});

it('summarizes a bank transaction reference', function (): void {
    $account = Account::create([
        'type' => 'cash',
        'name' => 'Cash',
        'balance' => 0,
        'is_master' => true,
    ]);

    $statement = BankStatement::create([
        'filename' => 'test.csv',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $bankTransaction = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now(),
        'description' => 'Wire transfer from member',
        'amount' => 500,
        'reference' => 'REF-1',
        'status' => 'imported',
        'hash' => md5('wire-transfer-test'),
    ]);

    $ledgerTransaction = app(AccountingService::class)->credit(
        $account,
        500,
        'Mirror',
        $bankTransaction,
    );

    expect($ledgerTransaction->referenceSummary())->toBe('Wire transfer from member');
});

it('returns null when there is no reference', function (): void {
    $transaction = Transaction::factory()->create([
        'reference_type' => null,
        'reference_id' => null,
    ]);

    expect($transaction->referenceSummary())->toBeNull();
});
