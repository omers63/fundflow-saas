<?php

declare(strict_types=1);

use App\Filament\Support\AccountTransactionManualAdjustmentHeaderActions;
use App\Filament\Tenant\Resources\BankAccounts\Tables\MasterBankLedgerTable;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Account::query()->delete();
});

test('manual master bank credit is single legged on master bank only', function () {
    $bank = Account::factory()->masterBank()->withBalance(100)->create();
    $cash = Account::factory()->masterCash()->withBalance(0)->create();

    AccountingService::withoutMemberCashCollection(
        fn () => app(AccountingService::class)->postManualCredit($bank, 50, 'Manual bank credit', null),
    );

    expect((float) $bank->fresh()->balance)->toBe(150.0)
        ->and((float) $cash->fresh()->balance)->toBe(0.0)
        ->and($bank->transactions()->count())->toBe(1)
        ->and($cash->transactions()->count())->toBe(0);
});

test('manual master bank credit with member tag does not mirror member cash', function () {
    $bank = Account::factory()->masterBank()->withBalance(0)->create();
    $cash = Account::factory()->masterCash()->withBalance(0)->create();
    $member = Member::factory()->create();
    $memberCash = Account::factory()->cash()->for($member)->withBalance(0)->create();

    AccountingService::withoutMemberCashCollection(
        fn () => app(AccountingService::class)->postManualCredit($bank, 75, 'Tagged bank credit', null, $member->id),
    );

    expect((float) $bank->fresh()->balance)->toBe(75.0)
        ->and((float) $cash->fresh()->balance)->toBe(0.0)
        ->and((float) $memberCash->fresh()->balance)->toBe(0.0)
        ->and($bank->transactions()->first()->member_id)->toBe($member->id)
        ->and($bank->transactions()->count())->toBe(1);
});

test('manual master bank debit is single legged on master bank only', function () {
    $bank = Account::factory()->masterBank()->withBalance(100)->create();
    $cash = Account::factory()->masterCash()->withBalance(50)->create();

    AccountingService::withoutMemberCashCollection(
        fn () => app(AccountingService::class)->postManualDebit($bank->fresh(), 20, 'Manual bank debit', null),
    );

    expect((float) $bank->fresh()->balance)->toBe(80.0)
        ->and((float) $cash->fresh()->balance)->toBe(50.0)
        ->and($bank->transactions()->count())->toBe(1)
        ->and($cash->transactions()->count())->toBe(0);
});

test('master bank ledger table exposes credit and debit header actions for admins', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-master-bank-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
    $this->actingAs($admin, 'tenant');

    Account::factory()->masterBank()->create();

    $actions = MasterBankLedgerTable::headerActions();

    expect($actions)->toHaveCount(3)
        ->and($actions[0]->getName())->toBe('manualCredit')
        ->and($actions[1]->getName())->toBe('manualDebit')
        ->and($actions[0]->isHidden())->toBeFalse()
        ->and($actions[1]->isHidden())->toBeFalse();
});

test('master bank transaction history uses manual ledger header actions', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-master-bank-rm-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
    $this->actingAs($admin, 'tenant');

    $bank = Account::factory()->masterBank()->create();

    $actions = AccountTransactionManualAdjustmentHeaderActions::make(fn (): Account => $bank);

    expect(collect($actions)->map->getName()->all())
        ->toContain('manualCredit', 'manualDebit');
});

test('master bank ledger table has no header actions when master bank is missing', function () {
    expect(MasterBankLedgerTable::headerActions())->toBe([]);
});

test('manual credit appears on the master bank ledger query but not statement lines', function () {
    $bank = Account::factory()->masterBank()->withBalance(0)->create();
    Account::factory()->masterCash()->withBalance(0)->create();

    AccountingService::withoutMemberCashCollection(
        fn () => app(AccountingService::class)->postManualCredit($bank, 25, 'Test credit', null),
    );

    expect($bank->transactions()->count())->toBe(1)
        ->and(BankTransaction::query()->count())->toBe(0);
});
