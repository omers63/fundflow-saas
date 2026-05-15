<?php

use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;

it('resolves the bank tab from the tab query string when Livewire is not bound', function () {
    request()->replace(['tab' => 'bank']);

    expect(MasterAccountResource::resolveListMasterAccountsTab())->toBe('bank');
});

it('resolves the expense tab from the tab query string when Livewire is not bound', function () {
    request()->replace(['tab' => 'expense']);

    expect(MasterAccountResource::resolveListMasterAccountsTab())->toBe('expense');
});

it('resolves the all tab from the tab query string when Livewire is not bound', function () {
    request()->replace(['tab' => 'all']);

    expect(MasterAccountResource::resolveListMasterAccountsTab())->toBe('all');
});

it('falls back to cash for an invalid tab query', function () {
    request()->replace(['tab' => 'invalid']);

    expect(MasterAccountResource::resolveListMasterAccountsTab())->toBe('cash');
});
