<?php

declare(strict_types=1);

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\MemberLatePaymentHistoryEvaluator;
use App\Support\ContributionCollectionStatus;
use App\Support\LoanSettings;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->evaluator = app(MemberLatePaymentHistoryEvaluator::class);
});

test('late payment history counts consecutive closed cycles with late contributions', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 15));

    $member = Member::create([
        'member_number' => 'MEM-LATE-'.uniqid(),
        'name' => 'Late History Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::create(2024, 12, 1),
        'status' => 'active',
    ]);

    foreach ([3, 4, 5] as $month) {
        Contribution::create([
            'member_id' => $member->id,
            'period' => Contribution::periodDate($month, 2026),
            'amount' => 1000,
            'amount_due' => 1000,
            'amount_collected' => 1000,
            'status' => 'posted',
            'collection_status' => ContributionCollectionStatus::COLLECTED,
            'posted_at' => Carbon::create(2026, $month, 20),
            'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
            'is_late' => true,
        ]);
    }

    $stats = $this->evaluator->evaluate($member);

    expect($stats['trailing_consecutive'])->toBe(3)
        ->and($stats['rolling_total'])->toBe(3)
        ->and($this->evaluator->shouldBlockLoanEligibility($stats['trailing_consecutive'], $stats['rolling_total']))->toBeTrue();

    Carbon::setTestNow();
});

test('late payment history ignores on-time settlements', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 15));

    $member = Member::create([
        'member_number' => 'MEM-OK-'.uniqid(),
        'name' => 'On Time Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::create(2026, 1, 1),
        'status' => 'active',
    ]);

    foreach ([1, 2, 3, 4, 5] as $month) {
        Contribution::create([
            'member_id' => $member->id,
            'period' => Contribution::periodDate($month, 2026),
            'amount' => 1000,
            'amount_due' => 1000,
            'amount_collected' => 1000,
            'status' => 'posted',
            'collection_status' => ContributionCollectionStatus::COLLECTED,
            'posted_at' => Carbon::create(2026, $month, 7),
            'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
            'is_late' => false,
        ]);
    }

    $stats = $this->evaluator->evaluate($member);

    expect($stats['trailing_consecutive'])->toBe(0)
        ->and($stats['rolling_total'])->toBe(0)
        ->and($this->evaluator->shouldBlockLoanEligibility($stats['trailing_consecutive'], $stats['rolling_total']))->toBeFalse();

    Carbon::setTestNow();
});

test('late payment history includes late loan installments', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 15));

    $member = Member::create([
        'member_number' => 'MEM-EMI-'.uniqid(),
        'name' => 'Late EMI Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::create(2025, 1, 1),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
        'interest_rate' => 10,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::create(2026, 1, 1),
        'disbursed_at' => Carbon::create(2026, 1, 1),
    ]);

    foreach ([3, 4, 5] as $month) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $month,
            'amount' => 1000,
            'due_date' => Carbon::create(2026, $month, 10),
            'status' => 'paid',
            'paid_at' => Carbon::create(2026, $month, 25),
            'is_late' => true,
        ]);
    }

    $stats = $this->evaluator->evaluate($member);

    expect($stats['trailing_consecutive'])->toBe(3)
        ->and($this->evaluator->shouldBlockLoanEligibility($stats['trailing_consecutive'], $stats['rolling_total']))->toBeTrue();

    Carbon::setTestNow();
});

test('loan settings late payment thresholds are configurable', function () {
    LoanSettings::save([
        'late_payment_consecutive_threshold' => 2,
        'late_payment_rolling_threshold' => 4,
        'late_payment_lookback_months' => 12,
    ]);

    expect(LoanSettings::latePaymentConsecutiveThreshold())->toBe(2)
        ->and(LoanSettings::latePaymentRollingThreshold())->toBe(4)
        ->and(LoanSettings::latePaymentLookbackMonths())->toBe(12);
});
