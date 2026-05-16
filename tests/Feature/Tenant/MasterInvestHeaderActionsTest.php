<?php

declare(strict_types=1);

use App\Filament\Support\MasterInvestHeaderActions;
use App\Models\Tenant\Account;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Account::query()->delete();
});

test('fund invest disburse invest and record return header actions are visible on master invest for admins', function () {
    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-invest-actions-'.uniqid('', true).'@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
    $this->actingAs($admin, 'tenant');

    $account = Account::factory()->masterInvest()->create();
    $actions = MasterInvestHeaderActions::make(fn () => $account);

    expect($actions)->toHaveCount(3)
        ->and($actions[0]->getName())->toBe('fundInvest')
        ->and($actions[0]->getLabel())->toBe(__('Fund Invest'))
        ->and($actions[1]->getName())->toBe('disburseInvest')
        ->and($actions[1]->getLabel())->toBe(__('Disburse Invest'))
        ->and($actions[2]->getName())->toBe('recordReturn')
        ->and($actions[2]->getLabel())->toBe(__('Record Return'))
        ->and($actions[0]->isHidden())->toBeFalse()
        ->and($actions[1]->isHidden())->toBeFalse()
        ->and($actions[2]->isHidden())->toBeFalse();
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
        ->and($actions[1]->isHidden())->toBeTrue()
        ->and($actions[2]->isHidden())->toBeTrue();
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
        ->and((float) $masterInvest->fresh()->balance)->toBe(5_000.0);
});

test('disburse invest moves funds through master cash and debits master invest', function () {
    Account::factory()->masterCash()->withBalance(0)->create();
    $masterInvest = Account::factory()->masterInvest()->withBalance(2_000)->create();

    app(AccountingService::class)->disburseReserveAccountByCheck(
        $masterInvest,
        750,
        'Check #2002',
    );

    expect((float) $masterInvest->fresh()->balance)->toBe(1_250.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(0.0);
});

test('record investment return credits master invest without changing master cash balance', function () {
    Account::factory()->masterCash()->withBalance(0)->create();
    $masterInvest = Account::factory()->masterInvest()->withBalance(0)->create();

    app(AccountingService::class)->recordInvestmentReturn(
        1_200,
        'Q1 return',
    );

    expect((float) $masterInvest->fresh()->balance)->toBe(1_200.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(0.0)
        ->and($masterInvest->transactions()->where('type', 'credit')->count())->toBe(1)
        ->and(Account::masterCash()->transactions()->count())->toBe(2);
});
