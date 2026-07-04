<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Services\LoanInsightsService;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    $this->service = app(LoanInsightsService::class);

    Loan::query()->delete();
    FundTier::query()->delete();
    LoanTier::query()->delete();
    Member::query()->delete();

    Account::query()->delete();
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100_000, 'is_master' => true]);
});

test('portfolio snapshot aggregates active loan amount and outstanding totals', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));

    $member = Member::factory()->create(['status' => 'active']);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'status' => 'active',
        'applied_at' => now()->subMonth(),
        'approved_at' => now()->subMonth(),
        'disbursed_at' => now()->subMonth(),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 2000,
        'due_date' => BusinessDay::now()->addDays(10)->toDateString(),
        'status' => 'pending',
    ]);

    $snapshot = $this->service->portfolioSnapshot();

    expect($snapshot['pipeline']['active'])->toBe(1)
        ->and($snapshot['pipeline']['active_amount_total'])->toBe(12_000.0)
        ->and($snapshot['pipeline']['outstanding_total'])->toBe(2_000.0)
        ->and($snapshot['forecast'])->toHaveKeys(['next_30_days_count', 'next_30_days_amount', 'ready_to_disburse_amount', 'headroom_delta'])
        ->and($snapshot['forecast']['next_30_days_count'])->toBe(1)
        ->and($snapshot['forecast']['next_30_days_amount'])->toBe(2_000.0);

    Carbon::setTestNow();
});

test('portfolio snapshot returns pipeline counts and hero', function () {
    $member = Member::factory()->create(['status' => 'active']);

    Loan::create([
        'member_id' => $member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'interest_rate' => 10,
        'term_months' => 12,
        'status' => 'pending',
        'applied_at' => now(),
    ]);

    $snapshot = $this->service->portfolioSnapshot();

    expect($snapshot)->toHaveKeys(['hero', 'kpis', 'pipeline', 'trend', 'currency'])
        ->and($snapshot['pipeline']['needs_decision'])->toBe(1)
        ->and($snapshot['hero']['tone'])->toBe('amber')
        ->and($snapshot['forecast'])->toHaveKeys(['next_30_days_count', 'available_fund_headroom'])
        ->and($snapshot['trend'])->toHaveCount(6)
        ->and($snapshot['trend'][0])->toHaveKeys(['label', 'expected_count', 'collection_rate']);

    $volumeTrend = $this->service->sixMonthLoanVolumeTrend();

    expect($volumeTrend)->toHaveCount(6)
        ->and($volumeTrend[0])->toHaveKeys(['label', 'total', 'active', 'pending', 'completed']);
});

test('loan detail snapshot includes stepper and relation summaries', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 8000,
        'amount_requested' => 8000,
        'amount_approved' => 8000,
        'interest_rate' => 10,
        'term_months' => 12,
        'status' => 'approved',
        'applied_at' => now()->subWeek(),
        'approved_at' => now(),
    ]);

    $snapshot = $this->service->loanDetailSnapshot($loan);

    expect($snapshot)->toHaveKeys(['steps', 'snapshot', 'next_due', 'guarantor'])
        ->and($snapshot['steps'])->not->toBeEmpty()
        ->and(collect($snapshot['steps'])->pluck('key'))->toContain('under_review')
        ->and($snapshot['snapshot'])->toHaveKeys(['requested', 'approved', 'disburse_percent', 'repay_percent']);
});

test('fund tiers snapshot reports utilization', function () {
    LoanTier::query()->delete();
    FundTier::query()->delete();

    $tier = LoanTier::create([
        'tier_number' => 99,
        'label' => 'Standard',
        'min_amount' => 1000,
        'max_amount' => 50_000,
        'min_monthly_installment' => 500,
        'is_active' => true,
    ]);

    FundTier::create([
        'tier_number' => 99,
        'label' => 'Pool A',
        'loan_tier_id' => $tier->id,
        'percentage' => 25,
        'is_active' => true,
    ]);

    $snapshot = $this->service->fundTiersSnapshot();

    expect($snapshot)->toHaveKeys(['utilization', 'breakdown', 'kpis'])
        ->and($snapshot['breakdown'])->toHaveCount(1);
});

test('emi collected snapshot includes cycle forecast fields', function () {
    $snapshot = $this->service->emiCollectedSnapshot();

    expect($snapshot['forecast'])->toHaveKeys([
        'projected_close_percent',
        'days_remaining',
        'ready_cash_total',
        'uncovered_amount',
    ]);
});
