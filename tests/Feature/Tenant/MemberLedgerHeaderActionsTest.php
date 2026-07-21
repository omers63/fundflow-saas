<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\Accounts\Pages\ViewAccount;
use App\Filament\Tenant\Resources\Accounts\RelationManagers\TransactionsRelationManager;
use App\Filament\Tenant\Resources\MasterAccounts\Pages\ViewMasterAccount;
use App\Filament\Tenant\Resources\MasterAccounts\RelationManagers\TransactionsRelationManager as MasterTransactionsRelationManager;
use App\Filament\Tenant\Resources\Members\Pages\EditMember;
use App\Filament\Tenant\Resources\Members\RelationManagers\MemberTransactionsTabsRelationManager;
use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
});

/**
 * @return list<string>
 */
function ledgerHeaderActionNames(object $livewire): array
{
    return collect($livewire->getTable()->getHeaderActions())
        ->flatMap(function (Action|ActionGroup $action): array {
            if ($action instanceof ActionGroup) {
                return $action->getFlatActions();
            }

            return [$action];
        })
        ->map(fn (Action $action): string => $action->getName())
        ->values()
        ->all();
}

test('member fund ledger registers manual credit and debit header actions for admins', function () {
    $admin = User::create([
        'name' => 'Fund Ledger Admin',
        'email' => 'fund-ledger-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $member = Member::factory()->create();

    $component = Livewire::actingAs($admin, 'tenant')
        ->test(MemberTransactionsTabsRelationManager::class, [
            'ownerRecord' => $member,
            'pageClass' => EditMember::class,
        ])
        ->call('setLedgerTab', 'fund')
        ->assertSuccessful();

    expect(ledgerHeaderActionNames($component->instance()))
        ->toContain('manualCredit', 'manualDebit');
});

test('member cash ledger registers manual credit and debit header actions for admins', function () {
    $admin = User::create([
        'name' => 'Cash Ledger Admin',
        'email' => 'cash-ledger-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $member = Member::factory()->create();

    $component = Livewire::actingAs($admin, 'tenant')
        ->test(MemberTransactionsTabsRelationManager::class, [
            'ownerRecord' => $member,
            'pageClass' => EditMember::class,
        ])
        ->assertSuccessful();

    expect(ledgerHeaderActionNames($component->instance()))
        ->toContain('manualCredit', 'manualDebit');
});

test('member ledger exposes a toggleable scope column and filter', function () {
    $admin = User::create([
        'name' => 'Ledger Scope Admin',
        'email' => 'ledger-scope-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $member = Member::factory()->create();

    $component = Livewire::actingAs($admin, 'tenant')
        ->test(MemberTransactionsTabsRelationManager::class, [
            'ownerRecord' => $member,
            'pageClass' => EditMember::class,
        ])
        ->assertSuccessful();

    $table = $component->instance()->getTable();
    $columns = collect($table->getColumns())->map(fn ($column) => $column->getName())->all();
    $filters = collect($table->getFilters())->map(fn ($filter) => $filter->getName())->all();

    expect($columns)->toContain('account_scope');
    expect($filters)->toContain('account_class');

    $scopeColumn = collect($table->getColumns())->first(
        fn ($column) => $column->getName() === 'account_scope',
    );

    expect($scopeColumn)->not->toBeNull();
    expect($scopeColumn->isToggleable())->toBeTrue();
});

test('master account ledger registers manual credit and debit header actions for admins', function () {
    $admin = User::create([
        'name' => 'Master Ledger Admin',
        'email' => 'master-ledger-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $account = Account::masterCash();

    $component = Livewire::actingAs($admin, 'tenant')
        ->test(MasterTransactionsRelationManager::class, [
            'ownerRecord' => $account,
            'pageClass' => ViewMasterAccount::class,
        ])
        ->assertSuccessful();

    expect(ledgerHeaderActionNames($component->instance()))
        ->toContain('manualCredit', 'manualDebit');
});

test('member account ledger registers manual credit and debit header actions for admins', function () {
    $admin = User::create([
        'name' => 'Account Ledger Admin',
        'email' => 'account-ledger-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $account = Account::factory()->cash()->withBalance(100)->create();

    $component = Livewire::actingAs($admin, 'tenant')
        ->test(TransactionsRelationManager::class, [
            'ownerRecord' => $account,
            'pageClass' => ViewAccount::class,
        ])
        ->assertSuccessful();

    expect(ledgerHeaderActionNames($component->instance()))
        ->toContain('manualCredit', 'manualDebit');
});
