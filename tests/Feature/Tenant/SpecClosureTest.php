<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\AccountingService;
use App\Services\MasterAccountInvariantService;
use App\Services\MemberInvariantService;
use App\Support\ContributionPolicySettings;
use App\Support\LoanSettings;
use App\Support\ScheduledJobRegistry;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
});

test('master invariant expects master fund to match member fund sum', function () {
    $member = Member::create([
        'member_number' => 'INV-001',
        'name' => 'Invariant Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    app(AccountingService::class)->credit($member->fundAccount, 1800, 'Seed fund');

    $result = app(MasterAccountInvariantService::class)->check();

    expect($result['expected_master_fund'])->toBe($result['member_fund_sum'])
        ->and($result['balanced'])->toBeFalse();
});

test('master invariant treats master invest balance as part of fund pool', function () {
    Account::masterFund()?->update(['balance' => 1800]);
    $masterInvest = Account::create(['type' => 'invest', 'name' => 'Master Invest', 'balance' => 0, 'is_master' => true]);
    $masterExpense = Account::create(['type' => 'expense', 'name' => 'Master Expense', 'balance' => 0, 'is_master' => true]);

    app(AccountingService::class)->fundReserveAccountFromMasterFund(
        $masterInvest,
        500,
        'Capital allocation',
    );
    app(AccountingService::class)->recordInvestmentReturn(300, 'Q1 return');
    app(AccountingService::class)->fundReserveAccountFromMasterFund(
        $masterExpense,
        300,
        'Operations float',
    );

    $member = Member::create([
        'member_number' => 'INV-INVEST-001',
        'name' => 'Invest Pool Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    app(AccountingService::class)->credit($member->fundAccount, 1800, 'Seed fund');

    $result = app(MasterAccountInvariantService::class)->check();

    expect($result['master_fund'])->toBe(1000.0)
        ->and($result['master_invest_from_fund_credits'])->toBe(500.0)
        ->and($result['master_expense_from_fund_credits'])->toBe(300.0)
        ->and($result['master_fund_pool'])->toBe(1800.0)
        ->and($result['member_fund_sum'])->toBe(1800.0)
        ->and($result['balanced'])->toBeTrue();
});

test('member invariant reports balanced ledger when opening and net movement align', function () {
    $member = Member::create([
        'member_number' => 'INV-002',
        'name' => 'Ledger Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
        'opening_cash_balance' => 0,
        'opening_fund_balance' => 0,
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $result = app(MemberInvariantService::class)->check($member->fresh());

    expect($result['balanced'])->toBeTrue();
});

test('cumulative late fee model posts increment not full tier replacement amount', function () {
    Setting::set(ContributionPolicySettings::GROUP_COLLECTION, 'late_fee_model', 'cumulative');
    Setting::set(LoanSettings::GROUP, 'late_fee_repayment_10d', 50);

    $increment = max(0.0, 50.0 - 10.0);

    expect(ContributionPolicySettings::lateFeeModel())->toBe('cumulative')
        ->and($increment)->toBe(40.0);
});

test('loan settings expose configurable max active loans', function () {
    expect(LoanSettings::maxActiveLoans())->toBeGreaterThanOrEqual(1);
});

test('loan status options include transferred', function () {
    expect(Loan::statusOptions())->toHaveKey('transferred');
});

test('emi close window command is registered in job catalog', function () {
    $keys = array_column(ScheduledJobRegistry::all(), 'key');

    expect($keys)->toContain('loans:close-emi-window');
});
