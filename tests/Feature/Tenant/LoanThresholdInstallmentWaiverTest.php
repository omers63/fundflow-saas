<?php

declare(strict_types=1);

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\Loans\LoanThresholdInstallmentWaiverService;
use App\Support\LoanSettings;
use Illuminate\Support\Facades\Auth;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Loan::query()->delete();
    Member::query()->delete();

    $this->waiver = app(LoanThresholdInstallmentWaiverService::class);
});

function createThresholdWaiverLoan(array $overrides = []): Loan
{
    $member = Member::create([
        'member_number' => 'WAIVER-'.uniqid(),
        'name' => 'Waiver Test Member',
        'monthly_contribution_amount' => 3000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    return Loan::create(array_merge([
        'member_id' => $member->id,
        'amount' => 100_000,
        'amount_requested' => 100_000,
        'amount_approved' => 100_000,
        'amount_disbursed' => 100_000,
        'member_portion' => 50_000,
        'master_portion' => 50_000,
        'settlement_threshold' => LoanSettings::settlementThreshold(),
        'repaid_to_master' => 50_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'status' => 'active',
        'applied_at' => now()->subYear(),
    ], $overrides));
}

test('threshold waiver is allowed after master portion is repaid and only settlement emis remain', function () {
    $loan = createThresholdWaiverLoan();

    $settlementPortion = round($loan->fullRepaymentThreshold() - (float) $loan->master_portion, 2);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 50_000,
        'due_date' => now()->subMonths(2),
        'status' => 'paid',
        'paid_at' => now()->subMonths(2),
        'amount_collected' => 50_000,
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => $settlementPortion,
        'due_date' => now()->addMonth(),
        'status' => 'pending',
    ]);

    expect($this->waiver->canWaive($loan->fresh()))->toBeTrue();
});

test('threshold waiver is blocked when master portion is not fully repaid', function () {
    $loan = createThresholdWaiverLoan(['repaid_to_master' => 40_000]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 16_000,
        'due_date' => now()->addMonth(),
        'status' => 'pending',
    ]);

    expect($this->waiver->canWaive($loan->fresh()))->toBeFalse()
        ->and($this->waiver->ineligibilityReason($loan->fresh()))
        ->toContain(__('The master fund portion must be fully repaid before threshold installments can be waived.'));
});

test('waiving threshold installments completes the loan without cash collection', function () {
    $admin = User::create([
        'name' => 'Waiver Admin',
        'email' => 'waiver-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Auth::guard('tenant')->login($admin);

    $loan = createThresholdWaiverLoan();
    $settlementPortion = round($loan->fullRepaymentThreshold() - (float) $loan->master_portion, 2);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 50_000,
        'due_date' => now()->subMonths(2),
        'status' => 'paid',
        'paid_at' => now()->subMonths(2),
        'amount_collected' => 50_000,
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => $settlementPortion,
        'due_date' => now()->addMonth(),
        'status' => 'pending',
    ]);

    $result = $this->waiver->waiveRemaining($loan->fresh(), 'Board approved exceptional waiver', $admin->id);

    expect($result->status)->toBe('completed')
        ->and($result->threshold_waiver_reason)->toBe('Board approved exceptional waiver')
        ->and($result->threshold_waived_by_id)->toBe($admin->id)
        ->and($result->installments()->where('status', 'waived')->count())->toBe(1)
        ->and($result->installments()->whereIn('status', ['pending', 'overdue'])->count())->toBe(0)
        ->and($result->settled_at)->not->toBeNull();
});

test('threshold waiver requires a reason', function () {
    $loan = createThresholdWaiverLoan(['repaid_to_master' => 50_000]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 16_000,
        'due_date' => now()->addMonth(),
        'status' => 'pending',
    ]);

    expect(fn () => $this->waiver->waiveRemaining($loan->fresh(), '   '))
        ->toThrow(InvalidArgumentException::class);
});
