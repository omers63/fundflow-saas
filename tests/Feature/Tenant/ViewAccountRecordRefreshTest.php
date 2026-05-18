<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Accounts\Pages\ViewAccount;
use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
});

test('view account page refreshes record when ledger insights event fires', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-refresh-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $member = Member::factory()->create();
    app(AccountingService::class)->createMemberAccounts($member);
    $cashAccount = $member->cashAccount;
    $cashAccount->update(['balance' => 100]);

    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($admin, 'tenant')
        ->test(ViewAccount::class, ['record' => $cashAccount->getRouteKey()])
        ->assertSuccessful()
        ->call('refreshAccountFromLedger', accountId: $cashAccount->id)
        ->assertSuccessful();

    app(AccountingService::class)->credit($cashAccount->fresh(), 50, 'Top up');

    Livewire::actingAs($admin, 'tenant')
        ->test(ViewAccount::class, ['record' => $cashAccount->getRouteKey()])
        ->call('refreshAccountFromLedger', accountId: $cashAccount->id)
        ->assertSet('data.balance', '150.00');
});
