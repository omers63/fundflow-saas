<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Support\ContributionCollectionStatus;
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

test('pending members include members who withdrew after the cycle opened', function () {
    $month = 11;
    $year = 2025;
    $cycleStart = $this->cycles->cycleStartAt($month, $year);

    $withdrawnAfter = Member::create([
        'member_number' => 'MEM-WD-AFTER',
        'name' => 'Withdrew After Cycle',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'withdrawn',
        'contribution_cycles_active' => false,
        'status_changed_at' => $cycleStart->copy()->addDays(10),
    ]);
    $this->accounting->createMemberAccounts($withdrawnAfter);

    $withdrawnBefore = Member::create([
        'member_number' => 'MEM-WD-BEFORE',
        'name' => 'Withdrew Before Cycle',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'withdrawn',
        'contribution_cycles_active' => false,
        'status_changed_at' => $cycleStart->copy()->subDay(),
    ]);
    $this->accounting->createMemberAccounts($withdrawnBefore);

    $novemberIds = $this->cycles->pendingMembersQueryForPeriod($month, $year)->pluck('id');
    $januaryIds = $this->cycles->pendingMembersQueryForPeriod(1, 2026)->pluck('id');

    expect($novemberIds)->toContain($withdrawnAfter->id)
        ->and($novemberIds)->not->toContain($withdrawnBefore->id)
        ->and($januaryIds)->not->toContain($withdrawnAfter->id)
        ->and($this->cycles->memberIsLiableForContributionPeriod($withdrawnAfter, $month, $year))->toBeTrue()
        ->and($this->cycles->memberIsLiableForContributionPeriod($withdrawnBefore, $month, $year))->toBeFalse();
});

test('pending members include members frozen after cycle open and exclude earlier freezes', function () {
    $month = 11;
    $year = 2025;
    $cycleStart = $this->cycles->cycleStartAt($month, $year);

    $frozenAfter = Member::create([
        'member_number' => 'MEM-FRZ-AFTER',
        'name' => 'Frozen After Cycle',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'inactive',
        'contribution_cycles_active' => false,
        'frozen_at' => $cycleStart->copy()->addDays(3),
        'status_changed_at' => $cycleStart->copy()->addDays(3),
    ]);
    $this->accounting->createMemberAccounts($frozenAfter);

    $frozenBefore = Member::create([
        'member_number' => 'MEM-FRZ-BEFORE',
        'name' => 'Frozen Before Cycle',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'inactive',
        'contribution_cycles_active' => false,
        'frozen_at' => $cycleStart->copy()->subDay(),
        'status_changed_at' => $cycleStart->copy()->subDay(),
    ]);
    $this->accounting->createMemberAccounts($frozenBefore);

    $pendingIds = $this->cycles->pendingMembersQueryForPeriod($month, $year)->pluck('id');

    expect($pendingIds)->toContain($frozenAfter->id)
        ->and($pendingIds)->not->toContain($frozenBefore->id);
});

test('collected contributions exclude loan-exempt members for the period', function () {
    $month = 6;
    $year = 2025;

    $liable = Member::create([
        'member_number' => 'MEM-COLLECTED',
        'name' => 'Collected Liable',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($liable);

    $exempt = Member::create([
        'member_number' => 'MEM-EX-POST',
        'name' => 'Exempt Posted',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($exempt);

    Loan::create([
        'member_id' => $exempt->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'amount_disbursed' => 10000,
        'interest_rate' => 10,
        'term_months' => 5,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2025-06-01'),
        'disbursed_at' => Carbon::parse('2025-06-01'),
        'first_repayment_month' => 6,
        'first_repayment_year' => 2025,
    ]);

    $liablePost = Contribution::create([
        'member_id' => $liable->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 500,
        'status' => 'posted',
        'posted_at' => now(),
        'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
    ]);

    Contribution::withoutEvents(function () use ($exempt, $month, $year): void {
        Contribution::create([
            'member_id' => $exempt->id,
            'period' => Contribution::periodDate($month, $year),
            'amount' => 500,
            'status' => 'posted',
            'posted_at' => now(),
            'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
        ]);
    });

    $collectedIds = $this->cycles->postedContributionsQueryForPeriod($month, $year)->pluck('id');

    expect($collectedIds)->toContain($liablePost->id)
        ->and($this->cycles->postedContributionCount($month, $year))->toBe(1)
        ->and($exempt->fresh()->isExemptFromContributions($month, $year))->toBeTrue();
});

test('collected contributions query includes partially paid pending rows', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    [$month, $year] = $this->cycles->currentOpenPeriod();

    $member = Member::create([
        'member_number' => 'MEM-PARTIAL',
        'name' => 'Partial Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $partial = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 200,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PARTIALLY_PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    $collectedIds = $this->cycles->postedContributionsQueryForPeriod($month, $year)->pluck('id');

    expect($collectedIds)->toContain($partial->id)
        ->and($this->cycles->postedContributionCount($month, $year))->toBe(1)
        ->and($this->cycles->pendingMembersQueryForPeriod($month, $year)->whereKey($member->id)->exists())->toBeTrue();
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

test('pending members with open ledger rows appear before import arrears cut-off', function () {
    $member = Member::create([
        'member_number' => 'MEM-CUTOFF-PEND',
        'name' => 'Cutoff Pending Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2021-02-01'),
        'contribution_arrears_cutoff_date' => Carbon::parse('2025-11-05'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(10, 2025),
        'amount' => 500,
        'status' => 'pending',
        'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
    ]);

    expect($this->cycles->pendingMembersQueryForPeriod(10, 2025)->where('id', $member->id)->exists())->toBeTrue()
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
