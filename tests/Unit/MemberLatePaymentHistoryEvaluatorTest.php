<?php

declare(strict_types=1);

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\MemberLatePaymentHistoryEvaluator;
use App\Support\ContributionCollectionStatus;
use App\Support\ContributionPolicySettings;
use App\Support\LoanSettings;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

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

    expect($stats['contribution']['trailing_consecutive'])->toBe(3)
        ->and($stats['contribution']['rolling_total'])->toBe(3)
        ->and($stats['repayment']['trailing_consecutive'])->toBe(0)
        ->and($this->evaluator->shouldBlockFromLateContributions($stats['contribution']))->toBeTrue()
        ->and($this->evaluator->shouldBlockLoanEligibility($stats))->toBeTrue();

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

    expect($stats['contribution']['trailing_consecutive'])->toBe(0)
        ->and($stats['contribution']['rolling_total'])->toBe(0)
        ->and($this->evaluator->shouldBlockLoanEligibility($stats))->toBeFalse();

    Carbon::setTestNow();
});

test('late payment history includes late loan installments under emi thresholds only', function () {
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

    expect($stats['repayment']['trailing_consecutive'])->toBe(3)
        ->and($stats['contribution']['trailing_consecutive'])->toBe(0)
        ->and($this->evaluator->shouldBlockFromLateRepayments($stats['repayment']))->toBeTrue()
        ->and($this->evaluator->shouldBlockFromLateContributions($stats['contribution']))->toBeFalse()
        ->and($this->evaluator->shouldBlockLoanEligibility($stats))->toBeTrue();

    Carbon::setTestNow();
});

test('contribution and emi late settlement thresholds are independently configurable', function () {
    LoanSettings::save([
        'late_payment_consecutive_threshold' => 2,
        'late_payment_rolling_threshold' => 4,
        'late_payment_lookback_months' => 12,
    ]);

    Setting::set('delinquency', 'late_settlement_consecutive_threshold', '5');
    Setting::set('delinquency', 'late_settlement_rolling_threshold', '8');
    Setting::set('delinquency', 'late_settlement_lookback_months', '24');

    expect(LoanSettings::latePaymentConsecutiveThreshold())->toBe(2)
        ->and(LoanSettings::latePaymentRollingThreshold())->toBe(4)
        ->and(LoanSettings::latePaymentLookbackMonths())->toBe(12)
        ->and(ContributionPolicySettings::lateSettlementConsecutiveThreshold())->toBe(5)
        ->and(ContributionPolicySettings::lateSettlementRollingThreshold())->toBe(8)
        ->and(ContributionPolicySettings::lateSettlementLookbackMonths())->toBe(24);
});
