<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanEligibilityOverride;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\Loans\LoanEligibilityOverrideService;
use App\Services\Loans\LoanEligibilityService;
use App\Services\Loans\LoanLifecycleService;
use App\Support\LoanEligibilityGate;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->accounting = app(AccountingService::class);

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    LoanEligibilityOverride::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
});

test('standing override bypasses a specific eligibility gate', function () {
    $member = Member::create([
        'member_number' => 'MEM-OVERRIDE',
        'name' => 'Low Fund Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $eligibility = app(LoanEligibilityService::class);

    expect($eligibility->isEligible($member))->toBeFalse();

    app(LoanEligibilityOverrideService::class)->record(
        (int) $member->id,
        LoanEligibilityGate::MIN_FUND_BALANCE,
        'Board approved despite low fund balance.',
    );

    expect($eligibility->isEligible($member))->toBeTrue();
});

test('admin can create loan for ineligible member with override reason', function () {
    $member = Member::create([
        'member_number' => 'MEM-ADMIN',
        'name' => 'Ineligible Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(6),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->fundAccount()->update(['balance' => 25000]);

    $lifecycle = app(LoanLifecycleService::class);

    expect(fn () => $lifecycle->applyForLoan($member, 10000))
        ->toThrow(InvalidArgumentException::class);

    $loan = $lifecycle->applyForLoan(
        $member,
        10000,
        'Emergency medical expense',
        adminOverrideEligibility: true,
        eligibilityOverrideReason: 'Emergency board approval.',
    );

    expect($loan->status)->toBe('pending')
        ->and($loan->amount_requested)->toBe('10000.00');

    $overrides = LoanEligibilityOverride::query()
        ->where('loan_id', $loan->id)
        ->pluck('gate')
        ->all();

    expect($overrides)->toContain(LoanEligibilityGate::MEMBERSHIP_TENURE);
});

test('admin override requires a reason when eligibility gates fail', function () {
    $member = Member::create([
        'member_number' => 'MEM-NOREASON',
        'name' => 'Needs Reason',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(6),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->fundAccount()->update(['balance' => 25000]);

    expect(fn () => app(LoanLifecycleService::class)->applyForLoan(
        $member,
        10000,
        adminOverrideEligibility: true,
        eligibilityOverrideReason: '',
    ))->toThrow(InvalidArgumentException::class);
});
