<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\MemberLoanCalculatorService;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    LoanTier::query()->delete();
    Member::query()->delete();

    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100_000, 'is_master' => true]);

    LoanTier::create([
        'tier_number' => 91,
        'label' => 'Standard',
        'min_amount' => 1000,
        'max_amount' => 50_000,
        'min_monthly_installment' => 500,
        'is_active' => true,
    ]);

    $this->member = Member::create([
        'member_number' => 'CALC-001',
        'name' => 'Calculator Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);
    $this->member->fundAccount->update(['balance' => 5000]);

    $this->service = app(MemberLoanCalculatorService::class);
});

test('calculations match tier split and installment count', function () {
    $results = $this->service->calculationsForAmount(10_000, $this->member->fresh());

    expect($results)->toHaveCount(1);

    $calc = $results[0];
    $settlementPct = LoanSettings::settlementThreshold();

    expect($calc['member_portion'])->toBe(5000.0)
        ->and($calc['master_portion'])->toBe(5000.0)
        ->and($calc['settlement_amt'])->toBe(10_000 * $settlementPct)
        ->and($calc['total_repay'])->toBe(5000.0 + (10_000 * $settlementPct))
        ->and($calc['installments'])->toBe((int) ceil($calc['total_repay'] / 500))
        ->and($calc['min_installment'])->toBe(500.0);
});

test('returns empty when amount is zero or out of tier range', function () {
    expect($this->service->calculationsForAmount(0, $this->member))->toBe([])
        ->and($this->service->calculationsForAmount(99_999, $this->member))->toBe([]);
});

test('uses split percentage strategy when selected', function () {
    LoanSettings::save(['member_funding_split_pct' => 30]);
    $this->member->fundAccount->update(['balance' => 20_000]);

    $calc = $this->service->calculationsForAmount(
        10_000,
        $this->member->fresh(),
        LoanFundingStrategy::SPLIT_PERCENTAGE,
    )[0];

    expect($calc['member_portion'])->toBe(3000.0)
        ->and($calc['master_portion'])->toBe(7000.0);
});
