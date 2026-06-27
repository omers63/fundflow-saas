<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\MasterAccounts\Pages\ListMasterAccounts;
use App\Models\Tenant\Account;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $this->actingAs(User::create([
        'name' => 'Master Accounts Tester',
        'email' => 'master-accounts-table@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');

    Account::query()->delete();
    Transaction::query()->delete();
});

test('master accounts invest tab shows net return instead of balance', function () {
    $invest = Account::factory()->masterInvest()->withBalance(250)->create();

    Transaction::factory()->for($invest)->create([
        'type' => 'debit',
        'amount' => 1_000,
        'description' => 'Placement A (invest out)',
    ]);

    Transaction::factory()->for($invest)->create([
        'type' => 'credit',
        'amount' => 400,
        'description' => 'Proceeds A (investment return)',
    ]);

    Livewire::test(ListMasterAccounts::class)
        ->set('activeTab', 'invest')
        ->assertSee(__('Net return'))
        ->assertSee('600.00');
});

test('master accounts cash tab still shows balance column label', function () {
    Account::factory()->masterCash()->withBalance(5_000)->create();

    Livewire::test(ListMasterAccounts::class)
        ->set('activeTab', 'cash')
        ->assertSee(__('Balance'))
        ->assertSee('5,000.00');
});

test('master accounts last activity uses latest ledger transaction date', function () {
    $cash = Account::factory()->masterCash()->create();

    Transaction::factory()->for($cash)->create([
        'type' => 'credit',
        'amount' => 250,
        'transacted_at' => '2030-06-15 09:30:00',
        'description' => 'Business-day ledger entry',
    ]);

    $cash->update(['updated_at' => '2020-01-01 00:00:00']);

    Livewire::test(ListMasterAccounts::class)
        ->set('activeTab', 'cash')
        ->assertSee('2030')
        ->assertDontSee('2020');
});
