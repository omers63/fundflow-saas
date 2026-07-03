<?php

use App\Filament\Tenant\Resources\MasterAccounts\MasterAccountResource;

it('always resolves the all tab regardless of query string', function () {
    request()->replace(['tab' => 'bank']);

    expect(MasterAccountResource::resolveListMasterAccountsTab())->toBe('all');
});

it('defaults to all when no tab query is present', function () {
    request()->replace([]);

    expect(MasterAccountResource::resolveListMasterAccountsTab())->toBe('all');
});

it('lists all as the first tab key', function () {
    expect(MasterAccountResource::tabKeys()[0])->toBe('all');
});
