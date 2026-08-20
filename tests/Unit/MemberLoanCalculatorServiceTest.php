<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\AccountingService;
use App\Services\MemberLoanCalculatorService;
use App\Support\BusinessDaySettings;
use App\Support\LoanExcessFundSettlementOption;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

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

afterEach(function () {
    BusinessDaySettings::saveFromForm(null);
});

test('calculations match tier split and installment count', function () {
    $results = $this->service->calculationsForAmount(10_000, $this->member->fresh());

    expect($results)->toHaveCount(1);

    $calc = $results[0];
    $settlementPct = LoanSettings::settlementThreshold();
    $eligibilityPct = LoanSettings::eligibilityThreshold();
    $settlementAmt = round(10_000 * $settlementPct, 2);

    expect($calc['member_portion'])->toBe(5000.0)
        ->and($calc['master_portion'])->toBe(5000.0)
        ->and($calc['settlement_amt'])->toBe($settlementAmt)
        ->and($calc['total_repay'])->toBe(round(5000.0 + $settlementAmt, 2))
        ->and($calc['installments'])->toBe((int) ceil($calc['total_repay'] / 500))
        ->and($calc['min_installment'])->toBe(500.0)
        ->and($calc['eligibility_base'])->toBe(50_000.0)
        ->and($calc['eligibility_amt'])->toBe(round(50_000.0 * $eligibilityPct, 2))
        ->and($calc['schedule']['rows'])->not->toBeEmpty();
});

test('settlement uses loan amount and eligibility uses the matching tier ceiling', function () {
    LoanSettings::save([
        'settlement_threshold_pct' => 0.20,
        'eligibility_threshold_pct' => 0.10,
    ]);

    $calc = $this->service->calculationsForAmount(10_000, $this->member->fresh())[0];

    expect($calc['settlement_amt'])->toBe(2000.0)
        ->and($calc['eligibility_base'])->toBe(50_000.0)
        ->and($calc['eligibility_amt'])->toBe(5000.0)
        ->and($calc['total_repay'])->toBe(7000.0)
        ->and($calc['installments'])->toBe(14);
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
        ->and($calc['master_portion'])->toBe(7000.0)
        ->and($calc['excess_fund'])->toBe(17_000.0);
});

test('split with early settlement estimates roll-up remaining months', function () {
    LoanSettings::save([
        'member_funding_split_pct' => 50,
        'allow_funding_strategy_split_with_early_settlement' => true,
    ]);
    $this->member->fundAccount->update(['balance' => 15_000]);

    $calc = $this->service->calculationsForAmount(
        10_000,
        $this->member->fresh(),
        LoanFundingStrategy::SPLIT_WITH_EARLY_SETTLEMENT,
        LoanExcessFundSettlementOption::ROLL_UP,
    )[0];

    expect($calc['member_portion'])->toBe(5000.0)
        ->and($calc['master_portion'])->toBe(5000.0)
        ->and($calc['excess_fund'])->toBe(10_000.0)
        ->and($calc['early_settlement_amount'])->toBe(10_000.0)
        ->and($calc['installments_covered'])->toBe(20)
        ->and($calc['installments'])->toBe((int) ceil($calc['total_repay'] / 500))
        ->and($calc['remaining_payment_months'])->toBe(0)
        ->and($calc['duration_months'])->toBe($calc['installments'])
        ->and($calc['installments'])->toBeGreaterThan(0);
});

test('member fund top-up uses available fund balance for portions', function () {
    $this->member->fundAccount->update(['balance' => 3500]);

    $calc = $this->service->calculationsForAmount(
        10_000,
        $this->member->fresh(),
        LoanFundingStrategy::MEMBER_FUND_TOPUP,
    )[0];

    expect($calc['member_portion'])->toBe(3500.0)
        ->and($calc['master_portion'])->toBe(6500.0)
        ->and($calc['excess_fund'])->toBe(0.0);
});

test('estimated schedule uses the current cycle, grace, and contribution due dates', function () {
    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm('2025-01-15');
    LoanSettings::save([
        'settlement_threshold_pct' => 0.20,
        'eligibility_threshold_pct' => 0.10,
        'max_allowed_grace_cycles' => 2,
    ]);

    $withGrace = $this->service->calculationsForAmount(
        10_000,
        $this->member->fresh(),
        graceCycles: 1,
    )[0];

    $schedule = $withGrace['schedule'];
    $kinds = array_column($schedule['rows'], 'kind');
    $emiRows = array_values(array_filter($schedule['rows'], fn (array $row): bool => $row['kind'] === 'emi'));
    $graceRows = array_values(array_filter($schedule['rows'], fn (array $row): bool => $row['kind'] === 'grace'));

    expect($schedule['grace_cycles'])->toBe(1)
        ->and($schedule['current_cycle_contribution'])->toBe('exempt_grace')
        ->and($graceRows)->toHaveCount(1)
        ->and($graceRows[0]['cycle_month'])->toBe(1)
        ->and($graceRows[0]['cycle_year'])->toBe(2025)
        ->and($kinds)->not->toContain('contribution_due')
        ->and($emiRows)->toHaveCount(14)
        ->and($emiRows[0]['cycle_month'])->toBe(2)
        ->and($schedule['first_due_date'])->toBe('2025-03-05')
        ->and($emiRows[0]['amount'])->toBe(500.0)
        ->and(round(array_sum(array_column($emiRows, 'amount')), 2))->toBe($withGrace['total_repay']);

    $withoutGrace = $this->service->calculationsForAmount(
        10_000,
        $this->member->fresh(),
        graceCycles: 0,
    )[0]['schedule'];

    expect($withoutGrace['grace_cycles'])->toBe(0)
        ->and($withoutGrace['current_cycle_contribution'])->toBe('due')
        ->and($withoutGrace['rows'][0]['kind'])->toBe('contribution_due')
        ->and($withoutGrace['rows'][0]['cycle_month'])->toBe(1)
        ->and(array_filter($withoutGrace['rows'], fn (array $row): bool => $row['kind'] === 'grace'))->toBeEmpty()
        ->and($withoutGrace['first_due_date'])->toBe('2025-03-05');
});

test('estimated schedule shifts grace when the current cycle contribution is already paid', function () {
    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm('2025-01-15');
    LoanSettings::save([
        'settlement_threshold_pct' => 0.20,
        'max_allowed_grace_cycles' => 2,
    ]);

    Contribution::factory()->posted()->create([
        'member_id' => $this->member->id,
        'period' => '2025-01-01',
        'amount' => 1000,
        'posted_at' => '2025-01-10',
        'paid_at' => '2025-01-10',
    ]);

    $schedule = $this->service->calculationsForAmount(
        10_000,
        $this->member->fresh(),
        graceCycles: 1,
    )[0]['schedule'];

    $graceRows = array_values(array_filter($schedule['rows'], fn (array $row): bool => $row['kind'] === 'grace'));

    expect($schedule['current_cycle_contribution'])->toBe('already_paid')
        ->and($schedule['rows'][0]['kind'])->toBe('contribution_paid')
        ->and($schedule['rows'][0]['cycle_month'])->toBe(1)
        ->and($graceRows)->toHaveCount(1)
        ->and($graceRows[0]['cycle_month'])->toBe(2)
        ->and($graceRows[0]['cycle_year'])->toBe(2025)
        ->and($schedule['first_due_date'])->toBe('2025-04-05');
});

test('roll-up and skip early settlement mark installments on the estimated schedule', function () {
    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm('2025-01-15');
    LoanSettings::save([
        'settlement_threshold_pct' => 0.20,
        'member_funding_split_pct' => 50,
        'allow_funding_strategy_split_with_early_settlement' => true,
        'max_allowed_grace_cycles' => 2,
    ]);
    $this->member->fundAccount->update(['balance' => 6500]);

    $rolled = $this->service->calculationsForAmount(
        10_000,
        $this->member->fresh(),
        LoanFundingStrategy::SPLIT_WITH_EARLY_SETTLEMENT,
        LoanExcessFundSettlementOption::ROLL_UP,
        graceCycles: 1,
    )[0];

    $rolledKinds = array_column($rolled['schedule']['rows'], 'kind');

    expect($rolled['excess_fund'])->toBe(1500.0)
        ->and($rolled['installments_covered'])->toBe(3)
        ->and($rolled['remaining_payment_months'])->toBe($rolled['installments'] - 6)
        ->and($rolled['duration_months'])->toBe($rolled['remaining_payment_months'] + 3)
        ->and($rolled['schedule']['payable_count'])->toBe($rolled['remaining_payment_months'])
        ->and(array_count_values($rolledKinds)['rolled_up'] ?? 0)->toBe(3)
        ->and(array_count_values($rolledKinds)['dropped'] ?? 0)->toBe(3)
        ->and(array_count_values($rolledKinds)['emi'] ?? 0)->toBe($rolled['remaining_payment_months']);

    $skipped = $this->service->calculationsForAmount(
        10_000,
        $this->member->fresh(),
        LoanFundingStrategy::SPLIT_WITH_EARLY_SETTLEMENT,
        LoanExcessFundSettlementOption::SKIP_FUTURE,
        graceCycles: 1,
    )[0];

    $skippedKinds = array_column($skipped['schedule']['rows'], 'kind');

    expect($skipped['installments_covered'])->toBe(3)
        ->and($skipped['remaining_payment_months'])->toBe($skipped['installments'] - 3)
        ->and($skipped['duration_months'])->toBe($skipped['installments'])
        ->and($skipped['schedule']['payable_count'])->toBe($skipped['remaining_payment_months'])
        ->and(array_count_values($skippedKinds)['skipped'] ?? 0)->toBe(3)
        ->and(array_count_values($skippedKinds)['emi'] ?? 0)->toBe($skipped['remaining_payment_months'])
        ->and($skippedKinds)->not->toContain('dropped');

    $skippedEmi = array_values(array_filter($skipped['schedule']['rows'], fn (array $row): bool => $row['kind'] === 'skipped'));
    expect($skippedEmi)->not->toBeEmpty()
        ->and(array_unique(array_column($skippedEmi, 'amount')))->toBe([0.0]);
});

test('future start date projects fund and treats the start cycle as paid', function () {
    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm('2025-01-15');
    LoanSettings::save([
        'settlement_threshold_pct' => 0.20,
        'max_allowed_grace_cycles' => 2,
    ]);
    $this->member->fundAccount->update(['balance' => 5000]);

    $projection = $this->service->fundProjection($this->member->fresh(), '2025-03-15', 1000);

    expect($projection['cycles_added'])->toBe(3)
        ->and($projection['projected_fund'])->toBe(8000.0)
        ->and($projection['start_cycle_month'])->toBe(3)
        ->and($projection['start_cycle_paid'])->toBeTrue();

    $calc = $this->service->calculationsForAmount(
        10_000,
        $this->member->fresh(),
        graceCycles: 1,
        startDate: '2025-03-15',
        projectedContributionAmount: 1000,
    )[0];

    $graceRows = array_values(array_filter($calc['schedule']['rows'], fn (array $row): bool => $row['kind'] === 'grace'));

    expect($calc['projected_fund'])->toBe(8000.0)
        ->and($calc['member_portion'])->toBe(8000.0)
        ->and($calc['master_portion'])->toBe(2000.0)
        ->and($calc['schedule']['rows'][0]['kind'])->toBe('contribution_paid')
        ->and($calc['schedule']['rows'][0]['cycle_month'])->toBe(3)
        ->and($graceRows)->toHaveCount(1)
        ->and($graceRows[0]['cycle_month'])->toBe(4)
        ->and($calc['schedule']['first_due_date'])->toBe('2025-06-05');

    $custom = $this->service->fundProjection($this->member->fresh(), '2025-03-15', 2000);

    expect($custom['contribution_amount'])->toBe(2000.0)
        ->and($custom['projected_fund'])->toBe(11000.0);
});
