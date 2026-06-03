<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Services\MasterAccountInvariantService;
use App\Services\ReconciliationService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    Transaction::query()->delete();
});

function invokeAssertMasterBalancedOrRound(): void
{
    $method = new ReflectionMethod(ReconciliationService::class, 'assertMasterBalancedOrRound');
    $method->setAccessible(true);
    $method->invoke(app(ReconciliationService::class));
}

function roundingAdjustmentCountOnAccount(Account $account): int
{
    return Transaction::query()
        ->where('account_id', $account->id)
        ->where('description', __('Reconciliation rounding adjustment'))
        ->count();
}

test('assert master balanced or round does not post fund rounding when pool matches members with reserve funding', function () {
    Account::factory()->masterCash()->withBalance(0)->create();
    $masterFund = Account::factory()->masterFund()->withBalance(430_000)->create();
    $masterInvest = Account::factory()->masterInvest()->create();
    $masterExpense = Account::factory()->masterExpense()->create();

    $member = Member::create([
        'member_number' => 'POOL-ROUND-01',
        'name' => 'Pool Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($member);
    $member->fundAccount->update(['balance' => 430_000]);

    $accounting->fundReserveAccountFromMasterFund($masterInvest, 11, 'Invest A');
    $accounting->fundReserveAccountFromMasterFund($masterInvest, 22, 'Invest B');
    $accounting->fundReserveAccountFromMasterFund($masterInvest, 44, 'Invest C');
    $accounting->fundReserveAccountFromMasterFund($masterExpense, 4, 'Expense A');
    $accounting->fundReserveAccountFromMasterFund($masterExpense, 99, 'Expense B');

    expect((float) $masterFund->fresh()->balance)->toBe(429_820.0)
        ->and(app(MasterAccountInvariantService::class)->check()['balanced'])->toBeTrue();

    invokeAssertMasterBalancedOrRound();

    expect(roundingAdjustmentCountOnAccount($masterFund->fresh()))->toBe(0);
});

test('assert master balanced or round posts cash rounding not fund rounding when pool is balanced but cash is out of tolerance', function () {
    Account::factory()->masterCash()->withBalance(1_000)->create();
    $masterFund = Account::factory()->masterFund()->withBalance(10_000)->create();
    $masterInvest = Account::factory()->masterInvest()->create();

    $member = Member::create([
        'member_number' => 'POOL-ROUND-02',
        'name' => 'Cash Drift Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($member);
    $member->fundAccount->update(['balance' => 10_000]);
    $member->cashAccount->update(['balance' => 1_005]);

    $accounting->fundReserveAccountFromMasterFund($masterInvest, 5_000, 'Reserve slice');

    $invariants = app(MasterAccountInvariantService::class)->check();

    expect($invariants['fund_delta'])->toBe(0.0)
        ->and($invariants['cash_delta'])->toBe(5.0)
        ->and($invariants['balanced'])->toBeFalse();

    try {
        invokeAssertMasterBalancedOrRound();
    } catch (InvalidArgumentException) {
        // Cash drift exceeds tolerance; batch should not silently post a fund adjustment.
    }

    expect(roundingAdjustmentCountOnAccount($masterFund->fresh()))->toBe(0)
        ->and(roundingAdjustmentCountOnAccount(Account::masterCash()))->toBe(0);
});

test('nightly reconciliation does not post fund rounding for balanced pool with reserve funding', function () {
    Account::factory()->masterCash()->withBalance(0)->create();
    $masterFund = Account::factory()->masterFund()->withBalance(430_000)->create();
    $masterInvest = Account::factory()->masterInvest()->create();
    $masterExpense = Account::factory()->masterExpense()->create();

    $member = Member::create([
        'member_number' => 'POOL-ROUND-03',
        'name' => 'Nightly Pool Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($member);
    $member->fundAccount->update(['balance' => 430_000]);

    $accounting->fundReserveAccountFromMasterFund($masterInvest, 77, 'Invest pool');
    $accounting->fundReserveAccountFromMasterFund($masterExpense, 103, 'Expense pool');

    app(ReconciliationService::class)->runNightlyBatch();

    expect(roundingAdjustmentCountOnAccount($masterFund->fresh()))->toBe(0);
});
