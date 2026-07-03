<?php

declare(strict_types=1);

use App\Filament\Support\MasterExpenseHeaderActions;
use App\Filament\Tenant\Resources\MasterAccounts\Pages\ViewMasterAccount;
use App\Filament\Tenant\Resources\MasterAccounts\RelationManagers\TransactionsRelationManager as MasterTransactionsRelationManager;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MasterExpenseDisbursementService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    Account::query()->delete();
});

test('fund expense and disburse expense header actions are visible on master expense for admins', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-expense-actions-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
    $this->actingAs($admin, 'tenant');

    $account = Account::factory()->masterExpense()->create();
    $actions = MasterExpenseHeaderActions::make(fn () => $account);

    expect($actions)->toHaveCount(2)
        ->and($actions[0]->getName())->toBe('fundExpense')
        ->and($actions[0]->getLabel())->toBe(__('Fund'))
        ->and($actions[1]->getName())->toBe('disburseExpense')
        ->and($actions[1]->getLabel())->toBe(__('Disburse'))
        ->and($actions[0]->isHidden())->toBeFalse()
        ->and($actions[1]->isHidden())->toBeFalse();
});

test('fund expense and disburse expense header actions are hidden on non-expense master accounts', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-expense-hidden-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
    $this->actingAs($admin, 'tenant');

    $account = Account::factory()->masterCash()->create();
    $actions = MasterExpenseHeaderActions::make(fn () => $account);

    expect($actions[0]->isHidden())->toBeTrue()
        ->and($actions[1]->isHidden())->toBeTrue();
});

test('fund expense transfers from master fund to master expense', function () {
    $masterFund = Account::factory()->masterFund()->withBalance(10_000)->create();
    $masterExpense = Account::factory()->masterExpense()->withBalance(0)->create();

    app(AccountingService::class)->fundReserveAccountFromMasterFund(
        $masterExpense,
        2_500,
        'Operating reserve',
    );

    expect((float) $masterFund->fresh()->balance)->toBe(7_500.0)
        ->and((float) $masterExpense->fresh()->balance)->toBe(2_500.0)
        ->and($masterExpense->transactions()->count())->toBe(1)
        ->and($masterFund->transactions()->count())->toBe(1)
        ->and(ReconciliationException::query()
            ->where('exception_code', 'MASTER_FUND_POOL_DRIFT')
            ->where('status', ReconciliationException::STATUS_OPEN)
            ->count())->toBe(0);
});

test('disburse expense debits master expense only and creates uncleared bank line', function () {
    Account::factory()->masterFund()->withBalance(0)->create();
    Account::factory()->masterCash()->withBalance(10_000)->create();
    $masterExpense = Account::factory()->masterExpense()->withBalance(1_000)->create();

    $disbursement = app(MasterExpenseDisbursementService::class)->disburse(
        $masterExpense,
        400,
        'Check #1001',
    );

    expect((float) $masterExpense->fresh()->balance)->toBe(600.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(10_000.0)
        ->and($masterExpense->transactions()->count())->toBe(1)
        ->and(Account::masterCash()->transactions()->count())->toBe(0)
        ->and($disbursement->bankTransaction)->not->toBeNull()
        ->and($disbursement->bankTransaction->is_cleared)->toBeFalse()
        ->and((float) $disbursement->bankTransaction->amount)->toBe(-400.0)
        ->and(BankTransaction::query()->count())->toBe(1);
});

test('master expense ledger shows expense id column', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-expense-column-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $masterExpense = Account::factory()->masterExpense()->withBalance(1_000)->create();
    Account::factory()->masterCash()->withBalance(10_000)->create();

    Livewire::actingAs($admin, 'tenant')
        ->test(MasterTransactionsRelationManager::class, [
            'ownerRecord' => $masterExpense,
            'pageClass' => ViewMasterAccount::class,
        ])
        ->assertTableColumnExists('expense_id');
});

test('master expense ledger search ignores virtual expense id column', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-expense-search-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $masterExpense = Account::factory()->masterExpense()->withBalance(1_000)->create();

    Transaction::factory()->for($masterExpense)->create([
        'type' => 'credit',
        'amount' => 1,
        'balance_after' => 1,
        'description' => 'Office reserve funding',
    ]);

    Livewire::actingAs($admin, 'tenant')
        ->test(MasterTransactionsRelationManager::class, [
            'ownerRecord' => $masterExpense,
            'pageClass' => ViewMasterAccount::class,
        ])
        ->searchTable('Office')
        ->assertSuccessful();
});
