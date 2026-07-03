<?php

declare(strict_types=1);

use App\Filament\Support\ViewActions\ViewAccountTransactionAction;
use App\Filament\Tenant\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Tenant\Resources\Accounts\RelationManagers\TransactionsRelationManager;
use App\Models\Tenant\Account;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Support\LedgerSettings;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Table;
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
        'name' => 'Ledger Edit Delete Admin',
        'email' => 'ledger-edit-delete-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->account = Account::masterCash();
    $this->transaction = AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->credit($this->account, 100, 'Deposit'),
    );
});

test('edit delete and bulk delete actions are visible by default for tenant admins', function () {
    $this->actingAs($this->admin, 'tenant');

    $table = ViewAccountTransactionAction::configure(
        Table::make(app(TransactionsRelationManager::class))
    )->toolbarActions(ViewAccountTransactionAction::tenantToolbarActions());

    expect($table->getRecordAction($this->account))->toBe(EditAction::getDefaultName());

    Livewire::actingAs($this->admin, 'tenant')
        ->test(TransactionsRelationManager::class, [
            'ownerRecord' => $this->account,
            'pageClass' => ViewAccount::class,
        ])
        ->assertTableActionVisible('edit', $this->transaction)
        ->assertTableActionVisible('delete', $this->transaction);
});

test('edit delete and bulk delete actions are hidden when ledger setting is disabled', function () {
    LedgerSettings::saveFromForm(['ledger_show_edit_delete' => false]);

    $this->actingAs($this->admin, 'tenant');

    $table = ViewAccountTransactionAction::configure(
        Table::make(app(TransactionsRelationManager::class))
    )->toolbarActions(ViewAccountTransactionAction::tenantToolbarActions());

    expect($table->getRecordAction($this->account))->toBe(ViewAction::getDefaultName());

    Livewire::actingAs($this->admin, 'tenant')
        ->test(TransactionsRelationManager::class, [
            'ownerRecord' => $this->account,
            'pageClass' => ViewAccount::class,
        ])
        ->assertTableActionHidden('edit', $this->transaction)
        ->assertTableActionHidden('delete', $this->transaction);
});
