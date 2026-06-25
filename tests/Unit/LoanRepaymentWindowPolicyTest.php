<?php

declare(strict_types=1);

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Support\LoanRepaymentWindowPolicy;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('legacy migration repayment window opens at disbursement', function () {
    $policy = app(LoanRepaymentWindowPolicy::class);
    $disbursedAt = Carbon::parse('2020-07-10 14:30:00');

    expect($policy->legacyMigrationWindowOpensAt($disbursedAt)->toDateString())->toBe('2020-07-10')
        ->and($policy->acceptsRepaymentOn(Carbon::parse('2020-07-10'), $policy->legacyMigrationWindowOpensAt($disbursedAt)))->toBeTrue()
        ->and($policy->acceptsRepaymentOn(Carbon::parse('2020-07-29'), $policy->legacyMigrationWindowOpensAt($disbursedAt)))->toBeTrue()
        ->and($policy->acceptsRepaymentOn(Carbon::parse('2020-07-09'), $policy->legacyMigrationWindowOpensAt($disbursedAt)))->toBeFalse();
});

test('normal loan repayment window opens on contribution cycle start day after grace', function () {
    Setting::set('contribution', 'cycle_start_day', '6');

    $policy = app(LoanRepaymentWindowPolicy::class);
    $disbursedAt = Carbon::parse('2025-01-15');

    $opensAt = $policy->firstRepaymentCycleStartForDisbursement($disbursedAt, 1);

    expect($opensAt->toDateString())->toBe('2025-03-06');
});

test('normal loan without grace opens on cycle start of first repayment month', function () {
    Setting::set('contribution', 'cycle_start_day', '6');

    $policy = app(LoanRepaymentWindowPolicy::class);
    $disbursedAt = Carbon::parse('2020-07-10');

    $opensAt = $policy->firstRepaymentCycleStartForDisbursement($disbursedAt, 0);

    expect($opensAt->toDateString())->toBe('2020-08-06');
});

test('installment due date lands on cycle end day before next cycle start', function () {
    Setting::set('contribution', 'cycle_start_day', '6');

    $policy = app(LoanRepaymentWindowPolicy::class);

    expect($policy->installmentDueDateForCycle(8, 2020)->toDateString())->toBe('2020-09-05');
});

test('loan model exposes legacy and normal repayment window open dates', function () {
    Setting::set('contribution', 'cycle_start_day', '6');

    $member = Member::factory()->create();

    $loan = Loan::factory()->create([
        'member_id' => $member->id,
        'disbursed_at' => '2020-07-10',
        'first_repayment_month' => 8,
        'first_repayment_year' => 2020,
        'grace_cycles' => 0,
        'has_grace_cycle' => false,
    ]);

    expect($loan->repaymentWindowOpensAt(legacyMigration: true)->toDateString())->toBe('2020-07-10')
        ->and($loan->repaymentWindowOpensAt()->toDateString())->toBe('2020-08-06');
});
