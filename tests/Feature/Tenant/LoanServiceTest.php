<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionService;
use App\Services\LoanService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->accounting = app(AccountingService::class);
    $this->contributionService = app(ContributionService::class);
    $this->service = app(LoanService::class);

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    Contribution::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
});

test('member with less than 12 months is not eligible', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'New Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(6),
        'status' => 'active',
    ]);

    $result = $this->service->checkEligibility($member);

    expect($result['eligible'])->toBeFalse();
    expect($result['reasons'][0])->toContain('12 months');
});

test('suspended member is not eligible', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Suspended Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'suspended',
    ]);

    $result = $this->service->checkEligibility($member);

    expect($result['eligible'])->toBeFalse();
});

test('member with active loan is not eligible', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Test',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Loan::create([
        'member_id' => $member->id,
        'amount' => 10000,
        'interest_rate' => 10,
        'term_months' => 12,
        'monthly_repayment' => 917,
        'total_repaid' => 0,
        'status' => 'disbursed',
        'applied_at' => now(),
    ]);

    $result = $this->service->checkEligibility($member);

    expect($result['eligible'])->toBeFalse();
    expect($result['reasons'])->toContain('Member already has an active loan (only one active loan allowed).');
});

test('eligible member passes all checks', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Eligible Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 15000]);

    foreach (range(1, 3) as $i) {
        $period = now()->subMonths($i)->startOfMonth()->format('Y-m-d');
        $contribution = $this->contributionService->recordContribution($member, $period);
        $this->contributionService->postContribution($contribution);
    }

    $result = $this->service->checkEligibility($member);

    expect($result['eligible'])->toBeTrue();
    expect($result['reasons'])->toBeEmpty();
});

test('loan application creates a pending loan', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Test',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);

    $loan = $this->service->applyForLoan($member, 50000, 10, 12);

    expect($loan->status)->toBe('pending');
    expect($loan->amount)->toBe('50000.00');
    expect($loan->interest_rate)->toBe('10.00');
    expect($loan->term_months)->toBe(12);
    expect($loan->monthly_repayment)->toBe('4583.33');
});

test('loan disbursement debits master fund and member fund and credits member cash', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Test',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $masterFund = Account::masterFund();
    $masterFund->update(['balance' => 100000]);
    $member->fundAccount->update(['balance' => 30000]);

    $loan = $this->service->applyForLoan($member, 20000, 10, 12);
    $this->service->approveLoan($loan);
    $this->service->disburseLoan($loan);

    $loan->refresh();
    expect($loan->status)->toBe('disbursed');
    expect($loan->disbursed_at)->not->toBeNull();

    expect($member->cashAccount->fresh()->balance)->toBe('20000.00');
    expect($masterFund->fresh()->balance)->toBe('80000.00');
    expect($member->fundAccount->fresh()->balance)->toBe('10000.00');
});

test('member fund can go negative after loan disbursement', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Test',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $masterFund = Account::masterFund();
    $masterFund->update(['balance' => 100000]);
    $member->fundAccount->update(['balance' => 5000]);

    $loan = $this->service->applyForLoan($member, 20000, 10, 12);
    $this->service->approveLoan($loan);
    $this->service->disburseLoan($loan);

    expect($member->fundAccount->fresh()->balance)->toBe('-15000.00');
});

test('loan payout debits member cash and master cash', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Test',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $masterFund = Account::masterFund();
    $masterFund->update(['balance' => 100000]);
    $masterCash = Account::masterCash();
    $masterCash->update(['balance' => 50000]);

    $loan = $this->service->applyForLoan($member, 20000, 10, 12);
    $this->service->approveLoan($loan);
    $this->service->disburseLoan($loan);
    $this->service->payoutLoan($loan);

    $loan->refresh();
    expect($loan->status)->toBe('repaying');
    expect($member->cashAccount->fresh()->balance)->toBe('0.00');
    expect($masterCash->fresh()->balance)->toBe('30000.00');
});

test('loan repayment credits member fund and master fund', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Test',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $masterFund = Account::masterFund();
    $masterFund->update(['balance' => 100000]);
    $member->cashAccount->update(['balance' => 15000]);

    $loan = $this->service->applyForLoan($member, 10000, 10, 12);
    $this->service->approveLoan($loan);
    $this->service->disburseLoan($loan);

    $memberFundAfterDisburse = $member->fundAccount->fresh()->balance;
    $masterFundAfterDisburse = $masterFund->fresh()->balance;

    $this->service->recordRepayment($loan, 5000);

    expect($member->fundAccount->fresh()->balance)
        ->toBe(number_format((float) $memberFundAfterDisburse + 5000, 2, '.', ''));
    expect($masterFund->fresh()->balance)
        ->toBe(number_format((float) $masterFundAfterDisburse + 5000, 2, '.', ''));
});

test('loan completes when fully repaid', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Test',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $masterFund = Account::masterFund();
    $masterFund->update(['balance' => 100000]);
    $member->cashAccount->update(['balance' => 20000]);

    $loan = $this->service->applyForLoan($member, 10000, 10, 12);
    $this->service->approveLoan($loan);
    $this->service->disburseLoan($loan);

    $this->service->recordRepayment($loan, 11000);

    $loan->refresh();
    expect($loan->status)->toBe('completed');
    expect($loan->total_repaid)->toBe('11000.00');
    expect($loan->completed_at)->not->toBeNull();
});
