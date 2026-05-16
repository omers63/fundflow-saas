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

it('defaults to all when no tab query is present', function () {
    request()->replace([]);

    expect(MasterAccountResource::resolveListMasterAccountsTab())->toBe('all');
});

it('falls back to all for an invalid tab query', function () {
    request()->replace(['tab' => 'invalid']);

    expect(MasterAccountResource::resolveListMasterAccountsTab())->toBe('all');
});

it('lists all as the first tab key', function () {
    expect(MasterAccountResource::tabKeys()[0])->toBe('all');
});
