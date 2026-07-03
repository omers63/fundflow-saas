<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\InvestDisbursement;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    app()->setLocale('en');

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

    expect($transaction->referenceSummary())->toBeNull()
        ->and($transaction->modalReferenceItems())->toBe([]);
});

it('summarizes invest and expense disbursement references', function (): void {
    $account = Account::create([
        'type' => 'invest',
        'name' => 'Invest',
        'balance' => 0,
        'is_master' => true,
    ]);

    $disbursement = InvestDisbursement::create([
        'amount' => 1000,
        'description' => 'Property purchase',
        'transacted_at' => now(),
    ]);

    $transaction = Transaction::factory()->for($account)->create([
        'reference_type' => InvestDisbursement::class,
        'reference_id' => $disbursement->id,
    ]);

    $transaction->load('reference');

    expect($transaction->referenceSummary())->toBe('Investment #'.$disbursement->id)
        ->and($transaction->modalReferenceItems())->toBe([
            ['label' => 'Investment #', 'value' => (string) $disbursement->id],
            ['label' => 'Linked source', 'value' => 'Investment #'.$disbursement->id],
        ]);
});

it('includes referenced account for reversal entries', function (): void {
    $cash = Account::create([
        'type' => 'cash',
        'name' => 'Master Cash',
        'balance' => 1000,
        'is_master' => true,
    ]);

    $original = Transaction::factory()->for($cash)->create([
        'type' => 'credit',
        'amount' => 250,
        'description' => 'Manual credit',
    ]);

    $reversal = Transaction::factory()->for($cash)->create([
        'type' => 'debit',
        'amount' => 250,
        'reference_type' => Transaction::class,
        'reference_id' => $original->id,
        'description' => 'Reversal of #'.$original->id,
    ]);

    $items = $reversal->modalReferenceItems();

    expect($items)->toHaveCount(2)
        ->and($items[0]['label'])->toBe('Referenced account')
        ->and($items[0]['value'])->toBe('Master Cash — transaction #'.$original->id)
        ->and($items[1]['label'])->toBe('Linked source')
        ->and($items[1]['value'])->toBe('Transaction #'.$original->id);
});
