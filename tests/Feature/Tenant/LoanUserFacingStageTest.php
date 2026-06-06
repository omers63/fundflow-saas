<?php

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Support\Loans\LoanUserFacingStage;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

function makeStepperLoan(array $attributes = []): Loan
{
    $member = Member::create([
        'member_number' => 'MEM-' . uniqid(),
        'name' => 'Stepper Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);

    return Loan::create(array_merge([
        'member_id' => $member->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'amount_disbursed' => 0,
        'interest_rate' => 0,
        'term_months' => 12,
        'status' => 'pending',
        'applied_at' => now()->subWeek(),
    ], $attributes));
}

function stepKeys(Loan $loan): array
{
    return collect(LoanUserFacingStage::stepperFor($loan))->pluck('key')->all();
}

function currentStep(Loan $loan): ?array
{
    return collect(LoanUserFacingStage::stepperFor($loan))->firstWhere('state', 'current');
}

test('stepper follows legacy lifecycle order', function () {
    $loan = makeStepperLoan();

    expect(stepKeys($loan))->toBe([
        'applied',
        'under_review',
        'approved',
        'disbursed',
        'active',
        'repaying',
        'settled',
        'closed',
    ]);
});

test('pending loan is under review', function () {
    $loan = makeStepperLoan(['status' => 'pending']);

    expect(currentStep($loan))
        ->not->toBeNull()
        ->and(currentStep($loan)['key'])->toBe('under_review');
});

test('approved loan awaiting disbursement shows approved as current', function () {
    $loan = makeStepperLoan([
        'status' => 'approved',
        'approved_at' => now(),
    ]);

    expect(currentStep($loan)['key'])->toBe('approved');
});

test('partially disbursed loan shows disbursed progress', function () {
    $loan = makeStepperLoan([
        'status' => 'partially_disbursed',
        'approved_at' => now(),
        'amount_disbursed' => 4000,
    ]);

    $current = currentStep($loan);

    expect($current['key'])->toBe('disbursed')
        ->and($current['description'])->toContain('4,000.00')
        ->and($current['description'])->toContain('10,000.00');
});

test('active loan before first repayment shows active as current', function () {
    $loan = makeStepperLoan([
        'status' => 'active',
        'approved_at' => now()->subMonth(),
        'amount_disbursed' => 10000,
        'disbursed_at' => now()->subWeek(),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => now()->addMonth(),
        'amount' => 1000,
        'status' => 'pending',
    ]);

    expect(currentStep($loan->fresh(['installments']))['key'])->toBe('active');
});

test('active loan with installments shows repaying progress', function () {
    $loan = makeStepperLoan([
        'status' => 'active',
        'approved_at' => now()->subMonth(),
        'amount_disbursed' => 10000,
        'disbursed_at' => now()->subWeek(),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'due_date' => now()->subWeek(),
        'amount' => 1000,
        'status' => 'paid',
        'paid_at' => now(),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'due_date' => now()->addMonth(),
        'amount' => 1000,
        'status' => 'pending',
    ]);

    $current = currentStep($loan->fresh(['installments']));

    expect($current['key'])->toBe('repaying')
        ->and($current['description'])->toBe(__(':paid paid · :remaining remaining', [
                    'paid' => 1,
                    'remaining' => 1,
                ]));
});

test('completed loan marks all stages complete', function () {
    $loan = makeStepperLoan([
        'status' => 'completed',
        'approved_at' => now()->subMonths(3),
        'amount_disbursed' => 10000,
        'disbursed_at' => now()->subMonths(2),
        'settled_at' => now()->subWeek(),
    ]);

    $steps = LoanUserFacingStage::stepperFor($loan->fresh());

    expect(collect($steps)->every(fn(array $step): bool => $step['state'] === 'complete'))->toBeTrue();
});

test('rejected loan uses shortened terminal stepper', function () {
    $loan = makeStepperLoan([
        'status' => 'rejected',
        'rejection_reason' => 'Insufficient documentation',
        'rejected_at' => now(),
    ]);

    $steps = LoanUserFacingStage::stepperFor($loan);

    expect(stepKeys($loan))->toBe(['applied', 'under_review', 'closed'])
        ->and(currentStep($loan)['label'])->toBe(__('Rejected'))
        ->and($steps[2]['description'])->toBe('Insufficient documentation');
});
