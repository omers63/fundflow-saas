<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\AccountingService;
use App\Services\MemberLoanCalculatorService;
use App\Support\BusinessDaySettings;
use App\Support\LoanCalculatorCurrentLoanSettlement;
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
        ->and(array_count_values($skippedKinds)['paid'] ?? 0)->toBe(3)
        ->and(array_count_values($skippedKinds)['emi'] ?? 0)->toBe($skipped['remaining_payment_months'])
        ->and($skippedKinds)->not->toContain('dropped');

    $paidFromExcess = array_values(array_filter($skipped['schedule']['rows'], fn (array $row): bool => $row['kind'] === 'paid'));
    expect($paidFromExcess)->not->toBeEmpty()
        ->and(array_unique(array_column($paidFromExcess, 'amount')))->toBe([500.0]);
});

test('skip early settlement applies partial excess to the next cycle without shortening the schedule', function () {
    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm('2025-01-15');
    LoanSettings::save([
        'settlement_threshold_pct' => 0.20,
        'member_funding_split_pct' => 50,
        'allow_funding_strategy_split_with_early_settlement' => true,
        'max_allowed_grace_cycles' => 0,
    ]);
    $this->member->fundAccount->update(['balance' => 5600]);

    $skipped = $this->service->calculationsForAmount(
        10_000,
        $this->member->fresh(),
        LoanFundingStrategy::SPLIT_WITH_EARLY_SETTLEMENT,
        LoanExcessFundSettlementOption::SKIP_FUTURE,
        graceCycles: 0,
    )[0];

    // Excess 600 → 1 full EMI (500) paid from excess cash, remainder 100 reduces the next cycle.
    $rows = $skipped['schedule']['rows'];
    $kinds = array_column($rows, 'kind');
    $firstEmi = collect($rows)->firstWhere('kind', 'emi');

    expect($skipped['excess_fund'])->toBe(600.0)
        ->and($skipped['installments_covered'])->toBe(1)
        ->and($skipped['duration_months'])->toBe($skipped['installments'])
        ->and(array_count_values($kinds)['paid'] ?? 0)->toBe(1)
        ->and($firstEmi)->not->toBeNull()
        ->and($firstEmi['amount'])->toBe(round(
            Loan::scheduleInstallmentAmount(
                1,
                $skipped['installments'],
                500.0,
                $skipped['total_repay'],
            ) - 100.0,
            2,
        ));
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
        ->and($projection['cash_needed'])->toBe(3000.0)
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
        ->and($custom['projected_fund'])->toBe(11000.0)
        ->and($custom['cash_needed'])->toBe(6000.0)
        ->and($custom['loan_repayment_cycles'])->toBe(0)
        ->and($custom['loan_repayment_amount'])->toBe(0.0);
});

test('future start date settles an active loan with regular payments before adding contributions', function () {
    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm('2025-01-15');
    $this->member->fundAccount->update(['balance' => -6000]);
    $this->member->update(['monthly_contribution_amount' => 500]);

    $loan = Loan::create([
        'member_id' => $this->member->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'member_portion' => 6000,
        'master_portion' => 0,
        'interest_rate' => 0,
        'term_months' => 2,
        'monthly_repayment' => 3000,
        'status' => 'active',
        'applied_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);

    foreach ([1, 2] as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 3000,
            'due_date' => now()->addMonths($number),
            'status' => 'pending',
        ]);
    }

    $projection = $this->service->fundProjection($this->member->fresh(), '2025-10-15', 500);

    expect($projection['cycles_added'])->toBe(8)
        ->and($projection['loan_repayment_cycles'])->toBe(2)
        ->and($projection['loan_repayment_amount'])->toBe(6000.0)
        ->and($projection['loan_repayment_installment'])->toBe(3000.0)
        ->and($projection['loan_settlement_mode'])->toBe(LoanCalculatorCurrentLoanSettlement::REGULAR_PAYMENTS)
        ->and($projection['projected_fund'])->toBe(4000.0)
        ->and($projection['cash_needed'])->toBe(10000.0);

    $early = $this->service->fundProjection(
        $this->member->fresh(),
        '2025-10-15',
        500,
        LoanCalculatorCurrentLoanSettlement::PARTIAL_TO_MATURITY,
    );

    expect($early['cycles_added'])->toBe(10)
        ->and($early['loan_repayment_cycles'])->toBe(0)
        ->and($early['loan_repayment_amount'])->toBe(6000.0)
        ->and($early['loan_repayment_installment'])->toBeNull()
        ->and($early['loan_settlement_mode'])->toBe(LoanCalculatorCurrentLoanSettlement::PARTIAL_TO_MATURITY)
        ->and($early['projected_fund'])->toBe(5000.0)
        ->and($early['cash_needed'])->toBe(11000.0);
});

test('projected fund uses remaining installment amounts when the last EMI differs', function () {
    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm('2025-01-15');
    $this->member->fundAccount->update(['balance' => -6000]);

    $loan = Loan::create([
        'member_id' => $this->member->id,
        'amount' => 4500,
        'amount_requested' => 4500,
        'amount_approved' => 4500,
        'amount_disbursed' => 4500,
        'member_portion' => 4500,
        'master_portion' => 0,
        'interest_rate' => 0,
        'term_months' => 2,
        'monthly_repayment' => 3000,
        'status' => 'active',
        'applied_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 3000,
        'due_date' => now()->addMonth(),
        'status' => 'pending',
    ]);
    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 1500,
        'due_date' => now()->addMonths(2),
        'status' => 'pending',
    ]);

    $projection = $this->service->fundProjection($this->member->fresh(), '2025-10-15', 500);

    expect($projection['loan_repayment_cycles'])->toBe(2)
        ->and($projection['loan_repayment_amount'])->toBe(4500.0)
        ->and($projection['loan_repayment_installment'])->toBeNull()
        ->and($projection['cycles_added'])->toBe(8)
        ->and($projection['projected_fund'])->toBe(2500.0);
});

test('projected fund applies only the EMIs that fit before the start date', function () {
    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm('2025-01-15');
    $this->member->fundAccount->update(['balance' => -6000]);

    $loan = Loan::create([
        'member_id' => $this->member->id,
        'amount' => 12000,
        'amount_requested' => 12000,
        'amount_approved' => 12000,
        'amount_disbursed' => 12000,
        'member_portion' => 12000,
        'master_portion' => 0,
        'interest_rate' => 0,
        'term_months' => 4,
        'monthly_repayment' => 3000,
        'status' => 'active',
        'applied_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);

    foreach ([1, 2, 3, 4] as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 3000,
            'due_date' => now()->addMonths($number),
            'status' => 'pending',
        ]);
    }

    $projection = $this->service->fundProjection($this->member->fresh(), '2025-03-15', 500);

    expect($projection['loan_repayment_cycles'])->toBe(3)
        ->and($projection['loan_repayment_amount'])->toBe(9000.0)
        ->and($projection['cycles_added'])->toBe(0)
        ->and($projection['projected_fund'])->toBe(3000.0);

    $early = $this->service->fundProjection(
        $this->member->fresh(),
        '2025-03-15',
        500,
        LoanCalculatorCurrentLoanSettlement::PARTIAL_TO_MATURITY,
    );

    expect($early['loan_repayment_cycles'])->toBe(0)
        ->and($early['loan_repayment_amount'])->toBe(12000.0)
        ->and($early['cycles_added'])->toBe(3)
        ->and($early['projected_fund'])->toBe(7500.0);
});

test('early settlement to maturity applies remaining installments even in the start cycle', function () {
    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm('2025-01-15');
    $this->member->fundAccount->update(['balance' => -6000]);

    $loan = Loan::create([
        'member_id' => $this->member->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'member_portion' => 6000,
        'master_portion' => 0,
        'interest_rate' => 0,
        'term_months' => 2,
        'monthly_repayment' => 3000,
        'status' => 'active',
        'applied_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);

    foreach ([1, 2] as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 3000,
            'due_date' => now()->addMonths($number),
            'status' => 'pending',
        ]);
    }

    $regular = $this->service->fundProjection($this->member->fresh(), '2025-01-15', 500);
    $early = $this->service->fundProjection(
        $this->member->fresh(),
        '2025-01-15',
        500,
        LoanCalculatorCurrentLoanSettlement::PARTIAL_TO_MATURITY,
    );

    expect($regular['loan_repayment_amount'])->toBe(0.0)
        ->and($regular['projected_fund'])->toBe(-6000.0)
        ->and($this->service->estimateBlockReason(10_000, $this->member->fresh(), startDate: '2025-01-15', projectedContributionAmount: 500))
        ->toBe(__('Projected fund at start must not be negative.'))
        ->and($early['loan_repayment_amount'])->toBe(6000.0)
        ->and($early['cycles_added'])->toBe(0)
        ->and($early['projected_fund'])->toBe(0.0)
        ->and($this->service->estimateBlockReason(
            10_000,
            $this->member->fresh(),
            startDate: '2025-01-15',
            projectedContributionAmount: 500,
            currentLoanSettlement: LoanCalculatorCurrentLoanSettlement::PARTIAL_TO_MATURITY,
        ))->toBeNull();
});

test('full early settlement restores pre-loan fund then adds contributions', function () {
    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm('2025-01-15');
    $this->member->fundAccount->update(['balance' => -5000]);
    $this->member->update(['monthly_contribution_amount' => 500]);

    $loan = Loan::create([
        'member_id' => $this->member->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'amount_disbursed' => 10000,
        'member_portion' => 5000,
        'master_portion' => 5000,
        'member_fund_balance_at_disbursement' => 5000,
        'interest_rate' => 0,
        'term_months' => 2,
        'monthly_repayment' => 3500,
        'status' => 'active',
        'applied_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);

    foreach ([1, 2] as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 3500,
            'due_date' => now()->addMonths($number),
            'status' => 'pending',
        ]);
    }

    $regular = $this->service->fundProjection($this->member->fresh(), '2025-10-15', 500);
    $partial = $this->service->fundProjection(
        $this->member->fresh(),
        '2025-10-15',
        500,
        LoanCalculatorCurrentLoanSettlement::PARTIAL_TO_MATURITY,
    );
    $full = $this->service->fundProjection(
        $this->member->fresh(),
        '2025-10-15',
        500,
        LoanCalculatorCurrentLoanSettlement::FULL_EARLY_SETTLEMENT,
    );

    expect($regular['projected_fund'])->toBe(6000.0)
        ->and($partial['projected_fund'])->toBe(7000.0)
        ->and($full['loan_settlement_mode'])->toBe(LoanCalculatorCurrentLoanSettlement::FULL_EARLY_SETTLEMENT)
        ->and($full['loan_repayment_cycles'])->toBe(0)
        ->and($full['loan_repayment_amount'])->toBe(10000.0)
        ->and($full['cycles_added'])->toBe(10)
        ->and($full['projected_fund'])->toBe(10000.0)
        ->and($regular['cash_needed'])->toBe(11000.0)
        ->and($partial['cash_needed'])->toBe(12000.0)
        ->and($full['cash_needed'])->toBe(15000.0);

    $loan->installments()->where('installment_number', 1)->update(['status' => 'paid']);
    $this->member->fundAccount->update(['balance' => -1500]);

    $afterPayment = $this->service->fundProjection(
        $this->member->fresh(),
        '2025-10-15',
        500,
        LoanCalculatorCurrentLoanSettlement::FULL_EARLY_SETTLEMENT,
    );

    expect($afterPayment['loan_repayment_amount'])->toBe(6500.0)
        ->and($afterPayment['projected_fund'])->toBe(10000.0)
        ->and($afterPayment['cash_needed'])->toBe(11500.0);
});

test('calculations are blocked when projected fund at start is negative', function () {
    $this->member->fundAccount->update(['balance' => -500]);

    expect($this->service->estimateBlockReason(10_000, $this->member->fresh()))
        ->toBe(__('Projected fund at start must not be negative.'))
        ->and($this->service->calculationsForAmount(10_000, $this->member->fresh()))->toBe([]);
});

test('calculations are blocked when member portion exceeds projected fund', function () {
    LoanSettings::save([
        'member_funding_split_pct' => 50,
        'allow_funding_strategy_split_percentage' => true,
    ]);
    $this->member->fundAccount->update(['balance' => 10_000]);

    $reason = $this->service->estimateBlockReason(
        100_000,
        $this->member->fresh(),
        LoanFundingStrategy::SPLIT_PERCENTAGE,
    );

    expect($reason)->toBe(__('Your member portion (:portion) exceeds the projected fund at start (:fund).', [
        'portion' => number_format(50_000.0, 2),
        'fund' => number_format(10_000.0, 2),
    ]))
        ->and($this->service->calculationsForAmount(
            100_000,
            $this->member->fresh(),
            LoanFundingStrategy::SPLIT_PERCENTAGE,
        ))->toBe([]);
});
