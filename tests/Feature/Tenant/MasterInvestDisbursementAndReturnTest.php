<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\ReconciliationException;
use App\Services\AccountingService;
use App\Services\BankClearingMatchService;
use App\Services\MasterInvestDisbursementService;
use App\Services\MasterInvestReturnService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Account::query()->delete();
    BankTransaction::query()->delete();

    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 20_000, 'is_master' => true]);
    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 10_000, 'is_master' => true]);
    Account::create(['type' => 'invest', 'name' => 'Master Invest', 'balance' => 0, 'is_master' => true]);
});

test('fund invest transfers from master fund to master invest', function () {
    $masterFund = Account::masterFund();
    $masterInvest = Account::masterInvest();

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

test('disburse invest debits master invest only and creates uncleared bank line', function () {
    $masterInvest = Account::masterInvest();
    $masterInvest->update(['balance' => 2_000]);

    $disbursement = app(MasterInvestDisbursementService::class)->disburse(
        $masterInvest,
        750,
        'Check #2002',
    );

    expect((float) $masterInvest->fresh()->balance)->toBe(1_250.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(10_000.0)
        ->and($masterInvest->transactions()->count())->toBe(1)
        ->and(Account::masterCash()->transactions()->count())->toBe(0)
        ->and($disbursement->bankTransaction)->not->toBeNull()
        ->and($disbursement->bankTransaction->is_cleared)->toBeFalse()
        ->and((float) $disbursement->bankTransaction->amount)->toBe(-750.0);
});

test('record return credits master invest only and creates positive uncleared bank line', function () {
    $masterInvest = Account::masterInvest();

    $investReturn = app(MasterInvestReturnService::class)->record(
        $masterInvest,
        1_200,
        'Q1 return',
    );

    expect((float) $masterInvest->fresh()->balance)->toBe(1_200.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(10_000.0)
        ->and(Account::masterCash()->transactions()->count())->toBe(0)
        ->and($investReturn->bankTransaction)->not->toBeNull()
        ->and($investReturn->bankTransaction->is_cleared)->toBeFalse()
        ->and((float) $investReturn->bankTransaction->amount)->toBe(1_200.0);
});

test('pending operational clearance scopes invest disbursements and returns to master invest', function () {
    $masterInvest = Account::masterInvest();
    $masterInvest->update(['balance' => 3_000]);

    app(MasterInvestDisbursementService::class)->disburse($masterInvest, 500, 'Outflow');
    app(MasterInvestReturnService::class)->record($masterInvest, 200, 'Inflow');

    $matching = app(BankClearingMatchService::class);
    $investScope = $matching
        ->applyPendingOperationalClearanceScopeForMasterAccount(BankTransaction::query(), $masterInvest)
        ->get();

    expect($investScope)->toHaveCount(2)
        ->and($investScope->pluck('amount')->map(fn($amount): float => (float) $amount)->all())
        ->toContain(-500.0, 200.0);
});
