<?php

declare(strict_types=1);

use App\Filament\Support\MasterInvestHeaderActions;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MasterInvestInService;
use App\Services\MasterInvestOutService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Account::query()->delete();
    ReconciliationException::query()->delete();
});

test('invest out and invest in header actions are visible on master invest for admins', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-invest-actions-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
    $this->actingAs($admin, 'tenant');

    $account = Account::factory()->masterInvest()->create();
    $actions = MasterInvestHeaderActions::make(fn () => $account);

    expect($actions)->toHaveCount(2)
        ->and($actions[0]->getName())->toBe('investOut')
        ->and($actions[0]->getLabel())->toBe(__('Out'))
        ->and($actions[1]->getName())->toBe('investIn')
        ->and($actions[1]->getLabel())->toBe(__('In'))
        ->and($actions[0]->isHidden())->toBeFalse()
        ->and($actions[1]->isHidden())->toBeFalse();
});

test('master invest header actions are hidden on non-invest master accounts', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-invest-hidden-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
    $this->actingAs($admin, 'tenant');

    $account = Account::factory()->masterExpense()->create();
    $actions = MasterInvestHeaderActions::make(fn () => $account);

    expect($actions[0]->isHidden())->toBeTrue()
        ->and($actions[1]->isHidden())->toBeTrue();
});

test('invest out transfers from master fund through master invest and creates uncleared bank line', function () {
    $masterFund = Account::factory()->masterFund()->withBalance(20_000)->create();
    Account::factory()->masterCash()->withBalance(10_000)->create();
    $masterInvest = Account::factory()->masterInvest()->withBalance(0)->create();

    app(MasterInvestOutService::class)->investOut(
        $masterInvest,
        5_000,
        'External investment placement',
    );

    expect((float) $masterFund->fresh()->balance)->toBe(15_000.0)
        ->and((float) $masterInvest->fresh()->balance)->toBe(0.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(10_000.0)
        ->and($masterInvest->transactions()->count())->toBe(2)
        ->and(BankTransaction::query()->count())->toBe(1)
        ->and((float) BankTransaction::query()->value('amount'))->toBe(-5_000.0);
});

test('fund invest transfers from master fund to master invest', function () {
    $masterFund = Account::factory()->masterFund()->withBalance(20_000)->create();
    $masterInvest = Account::factory()->masterInvest()->withBalance(0)->create();

    app(AccountingService::class)->fundReserveAccountFromMasterFund(
        $masterInvest,
        5_000,
        'Capital allocation',
    );

    expect((float) $masterFund->fresh()->balance)->toBe(15_000.0)
        ->and((float) $masterInvest->fresh()->balance)->toBe(5_000.0)
        ->and(ReconciliationException::query()
            ->where('exception_code', 'MASTER_FUND_POOL_DRIFT')
            ->where('status', ReconciliationException::STATUS_OPEN)
            ->count())->toBe(0);
});

test('invest in credits master invest transfers to master fund and leaves master cash unchanged', function () {
    $masterFund = Account::factory()->masterFund()->withBalance(20_000)->create();
    Account::factory()->masterCash()->withBalance(10_000)->create();
    $masterInvest = Account::factory()->masterInvest()->withBalance(0)->create();

    app(MasterInvestInService::class)->investIn(
        $masterInvest,
        1_200,
        'Q1 return',
    );

    expect((float) $masterFund->fresh()->balance)->toBe(21_200.0)
        ->and((float) $masterInvest->fresh()->balance)->toBe(0.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(10_000.0)
        ->and($masterInvest->transactions()->count())->toBe(2)
        ->and($masterInvest->transactions()->where('type', 'credit')->count())->toBe(1)
        ->and($masterInvest->transactions()->where('type', 'debit')->count())->toBe(1)
        ->and(Account::masterCash()->transactions()->count())->toBe(0)
        ->and(BankTransaction::query()->count())->toBe(1);
});
