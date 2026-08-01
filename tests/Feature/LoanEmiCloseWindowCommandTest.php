<?php

declare(strict_types=1);

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\LoanInstallmentCollectionCycleService;
use App\Support\BusinessDaySettings;
use App\Support\InstallmentCollectionStatus;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::set('contribution', 'cycle_start_day', '6');
    BusinessDaySettings::saveFromForm('2025-11-06');
    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();
});

afterEach(function () {
    BusinessDaySettings::saveFromForm(null);
});

test('emi close window command closes the prior cycle not the newly opened one', function () {
    $cycles = app(ContributionCycleService::class);

    expect($cycles->isCycleTransitionDay())->toBeTrue()
        ->and($cycles->currentOpenPeriod())->toBe([11, 2025])
        ->and($cycles->periodClosedByTransition())->toBe([10, 2025]);

    $member = Member::create([
        'member_number' => 'MEM-EMI-CLOSE',
        'name' => 'EMI Close Member',
        'email' => 'emi.close@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => '2024-01-01',
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'interest_rate' => 0,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => '2025-01-01',
        'disbursed_at' => '2025-01-01',
    ]);

    $octEmi = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => $cycles->cycleDueEndAt(10, 2025)->toDateString(),
        'status' => 'pending',
        'collection_status' => InstallmentCollectionStatus::PENDING,
    ]);

    $novEmi = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 1000,
        'due_date' => $cycles->cycleDueEndAt(11, 2025)->toDateString(),
        'status' => 'pending',
        'collection_status' => InstallmentCollectionStatus::PENDING,
    ]);

    // Prefer the service path: command resolves periodClosedByTransition (Oct), not currentOpenPeriod (Nov).
    expect($cycles->periodClosedByTransition())->toBe([10, 2025]);

    $flagged = app(LoanInstallmentCollectionCycleService::class)
        ->closeCollectionWindow(...$cycles->periodClosedByTransition());

    expect($flagged)->toBe(1)
        ->and($octEmi->fresh()->status)->toBe('overdue')
        ->and($novEmi->fresh()->status)->toBe('pending');

    // Safety: closing the newly opened Nov cycle on transition day must no-op.
    expect(app(LoanInstallmentCollectionCycleService::class)->closeCollectionWindow(11, 2025))->toBe(0)
        ->and($novEmi->fresh()->status)->toBe('pending');
});

test('closeCollectionWindow refuses to close a cycle that has not ended yet', function () {
    $cycles = app(ContributionCycleService::class);

    $member = Member::create([
        'member_number' => 'MEM-EMI-EARLY',
        'name' => 'EMI Early Member',
        'email' => 'emi.early@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => '2024-01-01',
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 3000,
        'amount_requested' => 3000,
        'amount_approved' => 3000,
        'amount_disbursed' => 3000,
        'interest_rate' => 0,
        'term_months' => 3,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => '2025-01-01',
        'disbursed_at' => '2025-01-01',
    ]);

    $novEmi = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => $cycles->cycleDueEndAt(11, 2025)->toDateString(),
        'status' => 'pending',
        'collection_status' => InstallmentCollectionStatus::PENDING,
    ]);

    $flagged = app(LoanInstallmentCollectionCycleService::class)->closeCollectionWindow(11, 2025);

    expect($flagged)->toBe(0)
        ->and($novEmi->fresh()->status)->toBe('pending');
});
