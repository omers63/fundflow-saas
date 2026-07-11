<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->cycles = app(ContributionCycleService::class);
    $this->accounting = app(AccountingService::class);

    Account::query()->delete();
    Member::query()->delete();
    Contribution::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();
});

test('pending members for period exclude posted contributors and pre-join members', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    [$month, $year] = $this->cycles->currentOpenPeriod();

    $eligible = Member::create([
        'member_number' => 'MEM-ELIG',
        'name' => 'Eligible Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($eligible);

    $posted = Member::create([
        'member_number' => 'MEM-POST',
        'name' => 'Posted Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($posted);

    Contribution::create([
        'member_id' => $posted->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 5000,
        'status' => 'posted',
        'posted_at' => now(),
        'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
    ]);

    $futureJoin = Member::create([
        'member_number' => 'MEM-NEW',
        'name' => 'Future Join',
        'monthly_contribution_amount' => 5000,
        'joined_at' => Carbon::create($year, $month, 1)->addMonths(2),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($futureJoin);

    $pendingIds = $this->cycles->pendingMembersQueryForPeriod($month, $year)->pluck('id');

    expect($pendingIds)->toContain($eligible->id)
        ->and($pendingIds)->not->toContain($posted->id)
        ->and($pendingIds)->not->toContain($futureJoin->id);

    Carbon::setTestNow();
});

test('pending members exclude loan-exempt members and non-posted contribution rows', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    [$month, $year] = $this->cycles->currentOpenPeriod();

    $exempt = Member::create([
        'member_number' => 'MEM-EX',
        'name' => 'Exempt Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($exempt);

    $loan = Loan::create([
        'member_id' => $exempt->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'amount_disbursed' => 10000,
        'interest_rate' => 10,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->subMonth(),
        'status' => 'pending',
    ]);

    $pendingOnly = Member::create([
        'member_number' => 'MEM-PEND',
        'name' => 'Pending Row Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($pendingOnly);

    Contribution::create([
        'member_id' => $pendingOnly->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 5000,
        'status' => 'pending',
        'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
    ]);

    $pendingIds = $this->cycles->pendingMembersQueryForPeriod($month, $year)->pluck('id');

    expect($pendingIds)->not->toContain($exempt->id)
        ->and($pendingIds)->toContain($pendingOnly->id);

    Carbon::setTestNow();
});

test('pending members exclude members who were emi exempt during a completed loan cycle', function () {
    $month = 6;
    $year = 2025;

    $exemptDuringLoan = Member::create([
        'member_number' => 'MEM-31',
        'name' => 'Historical EMI Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($exemptDuringLoan);

    Loan::create([
        'member_id' => $exemptDuringLoan->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'amount_disbursed' => 10000,
        'interest_rate' => 10,
        'term_months' => 5,
        'monthly_repayment' => 2000,
        'total_repaid' => 10000,
        'status' => 'completed',
        'applied_at' => Carbon::parse('2025-06-01'),
        'disbursed_at' => Carbon::parse('2025-06-01'),
        'completed_at' => Carbon::parse('2025-10-31'),
        'first_repayment_month' => 6,
        'first_repayment_year' => 2025,
    ]);

    $dueAfterLoan = Member::create([
        'member_number' => 'MEM-DUE-NOV',
        'name' => 'Due After Loan',
        'monthly_contribution_amount' => 5000,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dueAfterLoan);

    Loan::create([
        'member_id' => $dueAfterLoan->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'amount_disbursed' => 10000,
        'interest_rate' => 10,
        'term_months' => 5,
        'monthly_repayment' => 2000,
        'total_repaid' => 10000,
        'status' => 'completed',
        'applied_at' => Carbon::parse('2025-06-01'),
        'disbursed_at' => Carbon::parse('2025-06-01'),
        'completed_at' => Carbon::parse('2025-10-31'),
        'first_repayment_month' => 6,
        'first_repayment_year' => 2025,
    ]);

    $junePendingIds = $this->cycles->pendingMembersQueryForPeriod($month, $year)->pluck('id');
    $novemberPendingIds = $this->cycles->pendingMembersQueryForPeriod(11, $year)->pluck('id');

    expect($exemptDuringLoan->fresh()->isExemptFromContributions($month, $year))->toBeTrue()
        ->and($junePendingIds)->not->toContain($exemptDuringLoan->id)
        ->and($dueAfterLoan->fresh()->isExemptFromContributions(11, $year))->toBeFalse()
        ->and($novemberPendingIds)->toContain($dueAfterLoan->id);
});

test('pending members exclude periods before import arrears cut-off', function () {
    $member = Member::create([
        'member_number' => 'MEM-CUTOFF',
        'name' => 'Cutoff Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2021-02-01'),
        'contribution_arrears_cutoff_date' => Carbon::parse('2025-11-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    expect($this->cycles->pendingMembersQueryForPeriod(4, 2025)->where('id', $member->id)->exists())->toBeFalse()
        ->and($this->cycles->pendingMembersQueryForPeriod(10, 2025)->where('id', $member->id)->exists())->toBeFalse()
        ->and($this->cycles->pendingMembersQueryForPeriod(11, 2025)->where('id', $member->id)->exists())->toBeTrue();
});

test('pending members query can order by member cash account balance', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    [$month, $year] = $this->cycles->currentOpenPeriod();

    $lowCash = Member::create([
        'member_number' => 'MEM-LOW',
        'name' => 'Low Cash',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($lowCash);
    $lowCash->cashAccount->update(['balance' => 100]);

    $highCash = Member::create([
        'member_number' => 'MEM-HIGH',
        'name' => 'High Cash',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($highCash);
    $highCash->cashAccount->update(['balance' => 9000]);

    $orderedIds = $this->cycles->pendingMembersQueryForPeriod($month, $year)
        ->orderBy(
            Account::query()
                ->select('balance')
                ->whereColumn('accounts.member_id', 'members.id')
                ->where('type', 'cash')
                ->where('is_master', false)
                ->limit(1),
            'desc',
        )
        ->pluck('members.id');

    expect($orderedIds->first())->toBe($highCash->id);

    Carbon::setTestNow();
});
