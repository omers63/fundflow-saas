<?php

use App\Filament\Tenant\Resources\Accounts\AccountResource;

it('resolves the fund tab from the tab query string when Livewire is not bound', function () {
    request()->replace(['tab' => 'fund']);

    expect(AccountResource::resolveListMemberAccountsTab())->toBe('fund');
});

it('resolves the loans tab from the tab query string when Livewire is not bound', function () {
    request()->replace(['tab' => 'loans']);

    expect(AccountResource::resolveListMemberAccountsTab())->toBe('loans');
});

it('resolves the all tab from the tab query string when Livewire is not bound', function () {
    request()->replace(['tab' => 'all']);

    expect(AccountResource::resolveListMemberAccountsTab())->toBe('all');
});

it('defaults to all when no tab query is present', function () {
    request()->replace([]);

    expect(AccountResource::resolveListMemberAccountsTab())->toBe('all');
});

it('falls back to all for an invalid tab query', function () {
    request()->replace(['tab' => 'invalid']);

    expect(AccountResource::resolveListMemberAccountsTab())->toBe('all');
});
