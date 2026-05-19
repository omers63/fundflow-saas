<?php

use App\Filament\Member\Resources\MyAccounts\Pages\ListMyAccounts;
use App\Filament\Member\Resources\MyAccounts\Pages\ViewMyAccount;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('member');

    Member::query()->delete();
    User::query()->delete();

    $this->user = User::create([
        'name' => 'Portal Accounts',
        'email' => 'portal-accounts@test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->user->id,
        'member_number' => 'MEM-PACC',
        'name' => 'Portal Accounts',
        'email' => 'portal-accounts@test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);
    $this->member->load(['cashAccount', 'fundAccount']);
});

test('member can view accounts list with insights', function () {
    $this->actingAs($this->user, 'tenant');

    Livewire::test(ListMyAccounts::class)
        ->assertSuccessful()
        ->assertSee(__('Cash'))
        ->assertSee(__('Fund'));
});

test('member can view cash account detail with insights', function () {
    $this->actingAs($this->user, 'tenant');

    Livewire::test(ViewMyAccount::class, ['record' => $this->member->cashAccount->getKey()])
        ->assertSuccessful()
        ->assertSee(__('Recent ledger'));
});
