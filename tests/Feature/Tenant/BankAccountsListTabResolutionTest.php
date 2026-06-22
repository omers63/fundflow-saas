<?php

use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Support\BankClearingTabRegistry;

it('defaults to the work queue tab when no tab query is present', function () {
    request()->replace([]);

    expect(BankAccountsResource::resolveListBankAccountsTab())->toBe(BankClearingTabRegistry::TAB_QUEUE);
});

it('resolves the work queue tab from legacy transactions tab query', function () {
    request()->replace(['tab' => 'transactions']);

    expect(BankAccountsResource::resolveListBankAccountsTab())->toBe(BankClearingTabRegistry::TAB_QUEUE);
});

it('resolves the bank ledger tab from the tab query string', function () {
    request()->replace(['tab' => 'ledger']);

    expect(BankAccountsResource::resolveListBankAccountsTab())->toBe(BankClearingTabRegistry::TAB_LEDGER);
});

it('resolves the work queue tab from the legacy clearance tab query', function () {
    request()->replace(['tab' => 'clearance']);

    expect(BankAccountsResource::resolveListBankAccountsTab())->toBe(BankClearingTabRegistry::TAB_QUEUE);
});

it('resolves import history from the legacy statements tab query', function () {
    request()->replace(['tab' => 'statements']);

    expect(BankAccountsResource::resolveListBankAccountsTab())->toBe(BankClearingTabRegistry::TAB_HISTORY);
});

it('falls back to work queue for an invalid tab query', function () {
    request()->replace(['tab' => 'invalid']);

    expect(BankAccountsResource::resolveListBankAccountsTab())->toBe(BankClearingTabRegistry::TAB_QUEUE);
});

it('resolves legacy clearance tab to operations queue filter', function () {
    request()->replace(['tab' => 'clearance']);

    expect(BankAccountsResource::resolveQueueFilter())->toBe(BankClearingTabRegistry::FILTER_OPERATIONS);
});

it('resolves legacy imports tab to bank file queue filter', function () {
    request()->replace(['tab' => 'imports']);

    expect(BankAccountsResource::resolveQueueFilter())->toBe(BankClearingTabRegistry::FILTER_BANK_FILE);
});
