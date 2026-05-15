<?php

use App\Filament\Support\AccountTransactionAmountColumn;
use App\Models\Tenant\Account;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Transaction::query()->delete();

    $this->account = Account::create([
        'type' => 'cash',
        'name' => 'Test Cash',
        'balance' => 0,
        'is_master' => true,
    ]);

    $this->service = app(AccountingService::class);
});

it('computes footer net using signed debits and credits via select statements', function (): void {
    $this->service->credit($this->account, 1000.00, 'Credit');
    $this->service->debit($this->account->fresh(), 200.00, 'Debit');
    $this->service->credit($this->account->fresh(), 300.00, 'Credit');

    $query = Transaction::query()->where('account_id', $this->account->id);
    $summarizer = AccountTransactionAmountColumn::make()->getSummarizers()[0];
    $summarizer->query($query);

    $qualifiedAmount = $query->getModel()->qualifyColumn('amount');
    $selectStatements = $summarizer->getSelectStatements($qualifiedAmount);
    $alias = array_key_first($selectStatements);

    $result = $query->getModel()
        ->resolveConnection($query->getModel()->getConnectionName())
        ->table($query->toBase(), $query->getModel()->getTable())
        ->selectRaw("{$selectStatements[$alias]} as \"{$alias}\"")
        ->first();

    expect((float) $result->{$alias})->toBe(1100.0);
});

it('would over-count if debits were summed as positive amounts', function (): void {
    $this->service->credit($this->account, 1000.00, 'Credit');
    $this->service->debit($this->account->fresh(), 200.00, 'Debit');

    $plainSum = (float) Transaction::query()
        ->where('account_id', $this->account->id)
        ->sum('amount');

    expect($plainSum)->toBe(1200.0);
});
