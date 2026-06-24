<?php

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\MemberDelinquencyEvaluator;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Contribution::query()->delete();
    LoanInstallment::query()->delete();
    Loan::query()->delete();
    Member::query()->delete();
    Setting::query()->delete();
});

it('evaluates trailing miss from preloaded monthly contribution and repayment data', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));
    Setting::set('delinquency', 'total_miss_lookback_months', 6);

    $member = Member::factory()->create([
        'joined_at' => Carbon::create(2026, 1, 10),
        'monthly_contribution_amount' => 5000,
        'status' => 'active',
    ]);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(3, 2026),
        'amount' => 5000,
        'status' => 'posted',
        'posted_at' => now(),
        'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'amount_disbursed' => 10000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::create(2026, 4, 1),
        'disbursed_at' => Carbon::create(2026, 4, 1),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 2000,
        'due_date' => Carbon::create(2026, 4, 15),
        'status' => 'overdue',
    ]);

    $stats = app(MemberDelinquencyEvaluator::class)->evaluate($member->fresh());

    expect($stats['last_closed_month'])->toBe(4)
        ->and($stats['last_closed_year'])->toBe(2026)
        ->and($stats['trailing_consecutive'])->toBe(1)
        ->and($stats['rolling_total'])->toBeGreaterThanOrEqual(1);

    Carbon::setTestNow();
});

it('suspends when miss counts breach configured thresholds', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));
    Setting::set('delinquency', 'total_miss_lookback_months', 2);
    Setting::set('delinquency', 'consecutive_miss_threshold', 2);
    Setting::set('delinquency', 'total_miss_threshold', 2);

    $member = Member::factory()->create([
        'joined_at' => Carbon::create(2026, 1, 10),
        'monthly_contribution_amount' => 5000,
        'status' => 'active',
    ]);

    $evaluator = app(MemberDelinquencyEvaluator::class);
    $stats = $evaluator->evaluate($member->fresh());

    expect($stats['rolling_total'])->toBe(2)
        ->and($stats['trailing_consecutive'])->toBeGreaterThanOrEqual(2)
        ->and($evaluator->shouldSuspend($stats['trailing_consecutive'], $stats['rolling_total']))->toBeTrue();

    Carbon::setTestNow();
});

it('does not count contribution misses during a historical loan repayment cycle', function () {
    Carbon::setTestNow(Carbon::create(2024, 3, 20));
    Setting::set('delinquency', 'total_miss_lookback_months', 24);
    Setting::set('delinquency', 'total_miss_threshold', 3);

    $member = Member::factory()->create([
        'joined_at' => Carbon::create(2021, 1, 10),
        'monthly_contribution_amount' => 500,
        'status' => 'active',
    ]);

    Loan::create([
        'member_id' => $member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
        'interest_rate' => 0,
        'term_months' => 6,
        'monthly_repayment' => 500,
        'total_repaid' => 0,
        'status' => 'completed',
        'applied_at' => Carbon::create(2021, 8, 28),
        'disbursed_at' => Carbon::create(2021, 8, 28),
        'settled_at' => Carbon::create(2024, 2, 29),
    ]);

    $stats = app(MemberDelinquencyEvaluator::class)->evaluate($member->fresh());

    expect($stats['rolling_total'])->toBe(0)
        ->and($stats['trailing_consecutive'])->toBe(0);

    Carbon::setTestNow();
});

it('ignores closed periods before the legacy migration contribution cut-off', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));
    Setting::set('delinquency', 'total_miss_lookback_months', 60);
    Setting::set('delinquency', 'total_miss_threshold', 15);

    $member = Member::factory()->create([
        'joined_at' => Carbon::create(2014, 10, 29),
        'contribution_arrears_cutoff_date' => Carbon::create(2026, 6, 1),
        'monthly_contribution_amount' => 500,
        'status' => 'delinquent',
    ]);

    $stats = app(MemberDelinquencyEvaluator::class)->evaluate($member->fresh());

    expect($stats['rolling_total'])->toBe(0)
        ->and($stats['trailing_consecutive'])->toBe(0);

    Carbon::setTestNow();
});
