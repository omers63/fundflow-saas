<?php

declare(strict_types=1);

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\LoanInsightsService;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    Loan::query()->delete();
    Member::query()->delete();
});

test('outstanding breakdown splits scheduled and partial paid ahead of schedule', function () {
    $member = Member::create([
        'member_number' => 'OUT-'.uniqid(),
        'name' => 'Outstanding Breakdown Member',
        'monthly_contribution_amount' => 3000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 75_000,
        'amount_requested' => 75_000,
        'amount_approved' => 150_000,
        'amount_disbursed' => 150_000,
        'member_portion' => 75_000,
        'master_portion' => 75_000,
        'repaid_to_master' => 40_000,
        'interest_rate' => 0,
        'term_months' => 25,
        'status' => 'active',
        'applied_at' => now()->subYear(),
    ]);

    foreach (range(1, 13) as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 3000,
            'due_date' => now()->subMonths(14 - $number),
            'status' => 'paid',
            'paid_at' => now()->subMonths(14 - $number),
        ]);
    }

    foreach (range(14, 25) as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 3000,
            'due_date' => now()->addMonths($number - 13),
            'status' => 'pending',
        ]);
    }

    $breakdown = $loan->fresh(['installments'])->getOutstandingBreakdown();

    expect($breakdown['scheduled'])->toBe(36_000.0)
        ->and($breakdown['partial_paid'])->toBe(1_000.0)
        ->and($breakdown['ledger'])->toBe(35_000.0)
        ->and($breakdown['has_split'])->toBeTrue()
        ->and($loan->getOutstandingBalance())->toBe(35_000.0);
});

test('outstanding breakdown has no split when ledger matches schedule', function () {
    $member = Member::create([
        'member_number' => 'OUT-'.uniqid(),
        'name' => 'Aligned Outstanding Member',
        'monthly_contribution_amount' => 3000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 75_000,
        'amount_requested' => 75_000,
        'amount_approved' => 150_000,
        'amount_disbursed' => 150_000,
        'member_portion' => 75_000,
        'master_portion' => 75_000,
        'repaid_to_master' => 39_000,
        'interest_rate' => 0,
        'term_months' => 25,
        'status' => 'active',
        'applied_at' => now()->subYear(),
    ]);

    foreach (range(1, 13) as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 3000,
            'due_date' => now()->subMonths(14 - $number),
            'status' => 'paid',
            'paid_at' => now()->subMonths(14 - $number),
        ]);
    }

    foreach (range(14, 25) as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 3000,
            'due_date' => now()->addMonths($number - 13),
            'status' => 'pending',
        ]);
    }

    $breakdown = $loan->fresh(['installments'])->getOutstandingBreakdown();

    expect($breakdown['scheduled'])->toBe(36_000.0)
        ->and($breakdown['partial_paid'])->toBe(0.0)
        ->and($breakdown['ledger'])->toBe(36_000.0)
        ->and($breakdown['has_split'])->toBeFalse()
        ->and($loan->getOutstandingBalance())->toBe(36_000.0);
});

test('loan detail snapshot includes outstanding breakdown', function () {
    $member = Member::create([
        'member_number' => 'OUT-'.uniqid(),
        'name' => 'Snapshot Breakdown Member',
        'monthly_contribution_amount' => 3000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 75_000,
        'amount_requested' => 75_000,
        'amount_approved' => 150_000,
        'amount_disbursed' => 150_000,
        'member_portion' => 75_000,
        'master_portion' => 75_000,
        'repaid_to_master' => 40_000,
        'interest_rate' => 0,
        'term_months' => 25,
        'status' => 'active',
        'applied_at' => now()->subYear(),
    ]);

    foreach (range(1, 13) as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 3000,
            'due_date' => now()->subMonths(14 - $number),
            'status' => 'paid',
            'paid_at' => now()->subMonths(14 - $number),
        ]);
    }

    foreach (range(14, 25) as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 3000,
            'due_date' => now()->addMonths($number - 13),
            'status' => 'pending',
        ]);
    }

    $snapshot = app(LoanInsightsService::class)->loanDetailSnapshot($loan->fresh());

    expect($snapshot['snapshot']['outstanding'])->toBe(35_000.0)
        ->and($snapshot['snapshot']['outstanding_breakdown']['scheduled'])->toBe(36_000.0)
        ->and($snapshot['snapshot']['outstanding_breakdown']['partial_paid'])->toBe(1_000.0)
        ->and($snapshot['snapshot']['outstanding_breakdown']['has_split'])->toBeTrue();
});
