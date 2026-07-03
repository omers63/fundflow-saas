<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Tenant\Resources\Accounts\RelationManagers\TransactionsRelationManager;
use App\Models\Tenant\Account;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Support\LedgerSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    Account::query()->delete();

    Account::factory()->masterCash()->withBalance(1_000)->create();

    $this->accounting = app(AccountingService::class);

    $this->admin = User::create([
        'name' => 'Ledger Split Reverse Admin',
        'email' => 'ledger-split-reverse-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->account = Account::masterCash();
    $this->transaction = AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->credit($this->account, 100, 'Deposit'),
    );
});

test('split and reverse row actions are hidden by default for tenant admins', function () {
    Livewire::actingAs($this->admin, 'tenant')
        ->test(TransactionsRelationManager::class, [
            'ownerRecord' => $this->account,
            'pageClass' => ViewAccount::class,
        ])
        ->assertTableActionHidden('splitTransaction', $this->transaction)
        ->assertTableActionHidden('reverseEntry', $this->transaction);
});

test('split and reverse row actions are visible when ledger setting is enabled', function () {
    LedgerSettings::saveFromForm(['ledger_show_split_reverse' => true]);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(TransactionsRelationManager::class, [
            'ownerRecord' => $this->account,
            'pageClass' => ViewAccount::class,
        ])
        ->assertTableActionVisible('splitTransaction', $this->transaction)
        ->assertTableActionVisible('reverseEntry', $this->transaction);
});
