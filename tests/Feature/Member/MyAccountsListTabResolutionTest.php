<?php

use App\Filament\Member\Resources\MyAccounts\MyAccountResource;

it('resolves the fund tab from the tab query string when Livewire is not bound', function () {
    request()->replace(['tab' => 'fund']);

    expect(MyAccountResource::resolveListMyAccountsTab())->toBe('fund');
});

it('resolves the loans tab from the tab query string when Livewire is not bound', function () {
    request()->replace(['tab' => 'loans']);

    expect(MyAccountResource::resolveListMyAccountsTab())->toBe('loans');
});

it('resolves the all tab from the tab query string when Livewire is not bound', function () {
    request()->replace(['tab' => 'all']);

    expect(MyAccountResource::resolveListMyAccountsTab())->toBe('all');
});

it('falls back to cash for an invalid tab query', function () {
    request()->replace(['tab' => 'invalid']);

    expect(MyAccountResource::resolveListMyAccountsTab())->toBe('cash');
});
