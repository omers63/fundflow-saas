<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\AccountingService;
use App\Services\Loans\LoanInstallmentLateFeeService;
use App\Support\ContributionCollectionStatus;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 1_000_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 1_000_000, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
    $this->lateFees = app(LoanInstallmentLateFeeService::class);
});

afterEach(function () {
    Carbon::setTestNow();
});

test('nightly late fee tier stores zero amount when repayment tier fee is not configured', function () {
    Setting::set('late_fee', 'repayment_day_30', 0);
    Setting::set('late_fee', 'repayment_day_20', 0);
    Setting::set('late_fee', 'repayment_day_10', 0);
    Setting::set('late_fee', 'repayment_day_3', 0);

    Carbon::setTestNow(Carbon::parse('2026-06-29'));

    $member = Member::create([
        'member_number' => 'LATE-FEE-0',
        'name' => 'Zero Fee Borrower',
        'monthly_contribution_amount' => 0,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'interest_rate' => 10,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
    ]);

    $installment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-03-05'),
        'status' => 'overdue',
        'overdue_since' => Carbon::parse('2026-04-01'),
        'late_fee_tier' => 0,
        'late_fee_amount' => 0,
    ]);

    expect($this->lateFees->applyLateFeeTierForInstallment($installment))->toBeTrue();

    $installment->refresh();

    expect($installment->late_fee_tier)->toBe(4)
        ->and($installment->collection_status)->toBe(ContributionCollectionStatus::LATE_T4)
        ->and($installment->late_fee_amount)->not->toBeNull()
        ->and((float) $installment->late_fee_amount)->toBe(0.0);
});
