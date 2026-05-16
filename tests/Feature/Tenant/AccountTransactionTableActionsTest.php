<?php

declare(strict_types=1);

use App\Filament\Support\ViewActions\ViewAccountTransactionAction;
use App\Filament\Tenant\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Tenant\Resources\Accounts\RelationManagers\TransactionsRelationManager;
use App\Models\Tenant\Account;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Facades\Filament;
use Filament\Tables\Table;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Account::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
});

test('tenant transaction table registers edit delete and bulk delete for admins', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-txn-table-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
    $this->actingAs($admin, 'tenant');

    $account = Account::factory()->cash()->withBalance(100)->create();
    $table = ViewAccountTransactionAction::configure(
        Table::make(app(TransactionsRelationManager::class))
    )->toolbarActions(ViewAccountTransactionAction::tenantToolbarActions());

    expect($table->hasAction('edit'))->toBeTrue()
        ->and($table->hasAction('delete'))->toBeTrue()
        ->and($table->hasBulkAction('delete'))->toBeTrue()
        ->and($table->getRecordAction($account))->toBe(EditAction::getDefaultName());
});

test('tenant transaction table uses view record action for non-admin users', function () {
    $user = User::create([
        'name' => 'Staff',
        'email' => 'staff-txn-table-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);
    $this->actingAs($user, 'tenant');

    $account = Account::factory()->cash()->create();
    $table = ViewAccountTransactionAction::configure(
        Table::make(app(TransactionsRelationManager::class))
    );

    expect($table->getRecordAction($account))->toBe(ViewAction::getDefaultName());
});

test('admin can mount edit action from account transactions relation manager', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-txn-mount-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $account = Account::factory()->cash()->withBalance(250)->create();
    $transaction = Transaction::factory()->for($account)->create([
        'type' => 'credit',
        'amount' => 250,
        'balance_after' => 250,
        'description' => 'Opening',
        'transacted_at' => now(),
    ]);

    Filament::setCurrentPanel('tenant');

    $component = Livewire::actingAs($admin, 'tenant')
        ->test(TransactionsRelationManager::class, [
            'ownerRecord' => $account,
            'pageClass' => ViewAccount::class,
        ])
        ->assertSuccessful()
        ->mountTableAction('edit', (string) $transaction->getKey());

    expect($component->instance()->getMountedAction()?->getName())->toBe('edit');
});

test('delete transaction reverses balance and removes row', function () {
    $account = Account::factory()->cash()->withBalance(300)->create();
    $transaction = app(AccountingService::class)->credit($account, 200, 'Deposit');

    app(AccountingService::class)->deleteTransaction($transaction);

    $account->refresh();

    expect(Transaction::query()->find($transaction->id))->toBeNull()
        ->and((float) $account->balance)->toBe(300.0);
});
