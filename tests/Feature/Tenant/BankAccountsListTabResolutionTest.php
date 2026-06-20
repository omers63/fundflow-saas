<?php

use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;

it('defaults to the pending bank match tab when no tab query is present', function () {
    request()->replace([]);

    expect(BankAccountsResource::resolveListBankAccountsTab())->toBe('clearance');
});

it('resolves the statement lines tab from the legacy transactions tab query', function () {
    request()->replace(['tab' => 'transactions']);

    expect(BankAccountsResource::resolveListBankAccountsTab())->toBe('imports');
});

it('resolves the master bank ledger tab from the tab query string', function () {
    request()->replace(['tab' => 'ledger']);

    expect(BankAccountsResource::resolveListBankAccountsTab())->toBe('ledger');
});

it('resolves the pending bank match tab from the tab query string', function () {
    request()->replace(['tab' => 'clearance']);

    expect(BankAccountsResource::resolveListBankAccountsTab())->toBe('clearance');
});

it('falls back to pending bank match for an invalid tab query', function () {
    request()->replace(['tab' => 'invalid']);

    expect(BankAccountsResource::resolveListBankAccountsTab())->toBe('clearance');
});
