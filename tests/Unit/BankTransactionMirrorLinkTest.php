<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Services\FundFlowService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Transaction::query()->delete();
    BankTransaction::query()->delete();
    BankStatement::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
});

it('links master cash ledger entry from bank transaction', function (): void {
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
        'description' => 'Incoming wire',
        'amount' => 2500,
        'reference' => 'WIRE-1',
        'status' => 'imported',
        'hash' => md5('mirror-link-test'),
    ]);

    app(FundFlowService::class)->mirrorToCash([$bankTransaction->id]);

    $bankTransaction->refresh();
    $ledger = $bankTransaction->masterCashTransaction;

    expect($ledger)->not->toBeNull()
        ->and($ledger->sourcedBankTransaction()?->is($bankTransaction))->toBeTrue()
        ->and($bankTransaction->masterCashMirrorSummary())->toContain((string) $ledger->id)
        ->and($ledger->bankImportSummary())->toContain('Incoming wire');
});

it('resolves master cash mirror from morph when fk is missing', function (): void {
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
        'description' => 'Morph link test',
        'amount' => 500,
        'reference' => 'MORPH-1',
        'status' => 'mirrored',
        'hash' => md5('morph-fk-missing'),
    ]);

    $ledger = app(AccountingService::class)->mirror(Account::masterCash(), 500, 'Bank: Morph link test', $bankTransaction);

    expect($bankTransaction->fresh()->master_cash_transaction_id)->toBeNull()
        ->and($bankTransaction->resolveMasterCashTransaction())->is($ledger)->toBeTrue()
        ->and($bankTransaction->fresh()->master_cash_transaction_id)->toBe($ledger->id);
});

it('resolves bank import from morph reference when fk is missing', function (): void {
    $account = Account::masterCash();

    $bankTransaction = BankTransaction::create([
        'bank_statement_id' => BankStatement::create([
            'filename' => 'legacy.csv',
            'status' => 'completed',
            'total_rows' => 1,
            'imported_rows' => 1,
            'duplicate_rows' => 0,
        ])->id,
        'transaction_date' => now(),
        'description' => 'Legacy import',
        'amount' => 100,
        'reference' => 'LEG-1',
        'status' => 'mirrored',
        'hash' => md5('legacy-mirror-link'),
    ]);

    $ledger = app(AccountingService::class)->mirror($account, 100, 'Bank: Legacy import', $bankTransaction);

    expect($ledger->sourcedBankTransaction()?->is($bankTransaction))->toBeTrue();
});
