<?php

use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;

it('resolves the transactions tab from the tab query string when Livewire is not bound', function () {
    request()->replace(['tab' => 'transactions']);

    expect(BankAccountsResource::resolveListBankAccountsTab())->toBe('transactions');
});

it('falls back to statements for an invalid tab query', function () {
    request()->replace(['tab' => 'invalid']);

    expect(BankAccountsResource::resolveListBankAccountsTab())->toBe('statements');
});
