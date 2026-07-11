<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\ContributionService;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->cycles = app(ContributionCycleService::class);
    $this->accounting = app(AccountingService::class);
    Setting::set('contribution', 'cycle_start_day', '6');
});

test('loan completed before cycle start does not exempt the labelled cycle', function () {
    expect($this->cycles->loanRepaymentOverlapsContributionCycle(
        '2025-05-01',
        null,
        '2025-11-03',
        'completed',
        11,
        2025,
    ))->toBeFalse();
});

test('loan completed during cycle window exempts the labelled cycle', function () {
    expect($this->cycles->loanRepaymentOverlapsContributionCycle(
        '2025-05-01',
        null,
        '2025-11-10',
        'completed',
        11,
        2025,
    ))->toBeTrue();
});

test('loan completed during prior labelled cycle exempts that cycle not the next', function () {
    expect($this->cycles->loanRepaymentOverlapsContributionCycle(
        '2025-05-01',
        null,
        '2025-11-03',
        'completed',
        10,
        2025,
    ))->toBeTrue()
        ->and($this->cycles->loanRepaymentOverlapsContributionCycle(
            '2025-05-01',
            null,
            '2025-11-03',
            'completed',
            11,
            2025,
        ))->toBeFalse();
});

test('loan exemption respects configurable cycle start day', function () {
    Setting::set('contribution', 'cycle_start_day', '10');

    // October labelled cycle: 10 Oct – 9 Nov
    expect($this->cycles->loanRepaymentOverlapsContributionCycle(
        '2025-05-01',
        null,
        '2025-11-09',
        'completed',
        10,
        2025,
    ))->toBeTrue()
        // November labelled cycle: 10 Nov – 9 Dec (settlement on 9 Nov is before it opens)
        ->and($this->cycles->loanRepaymentOverlapsContributionCycle(
            '2025-05-01',
            null,
            '2025-11-09',
            'completed',
            11,
            2025,
        ))->toBeFalse()
        ->and($this->cycles->loanRepaymentOverlapsContributionCycle(
            '2025-05-01',
            null,
            '2025-11-12',
            'completed',
            11,
            2025,
        ))->toBeTrue();
});

test('member is not exempt for november cycle when loan settled on november 3rd with cycle start day 6', function () {
    $member = Member::create([
        'member_number' => 'MEM-29-LIKE',
        'name' => 'Early November Settlement',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2021-02-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Loan::create([
        'member_id' => $member->id,
        'amount' => 10_000,
        'amount_requested' => 10_000,
        'amount_approved' => 10_000,
        'amount_disbursed' => 10_000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 2000,
        'total_repaid' => 10_000,
        'status' => 'completed',
        'applied_at' => Carbon::parse('2025-05-01'),
        'disbursed_at' => Carbon::parse('2025-05-01'),
        'completed_at' => Carbon::parse('2025-11-03'),
    ]);

    expect($member->fresh()->isExemptFromContributions(10, 2025))->toBeTrue()
        ->and($member->fresh()->isExemptFromContributions(11, 2025))->toBeFalse()
        ->and(
            $this->cycles->pendingMembersQueryForPeriod(11, 2025)->where('id', $member->id)->exists()
        )->toBeTrue();
});

test('run contribution cycle does not skip member whose loan ended before november cycle start', function () {
    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();

    $member = Member::create([
        'member_number' => 'MEM-SKIP-FIX',
        'name' => 'Should Collect November',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2021-02-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 500]);
    $member = $member->fresh();
    $member->unsetRelation('accounts');

    Loan::create([
        'member_id' => $member->id,
        'amount' => 10_000,
        'amount_requested' => 10_000,
        'amount_approved' => 10_000,
        'amount_disbursed' => 10_000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 2000,
        'total_repaid' => 10_000,
        'status' => 'completed',
        'applied_at' => Carbon::parse('2025-05-01'),
        'disbursed_at' => Carbon::parse('2025-05-01'),
        'completed_at' => Carbon::parse('2025-11-03'),
    ]);

    $bucket = [];
    $outcome = app(ContributionService::class)->applyForPeriod($member, 11, 2025, $bucket);

    expect($outcome)->not->toBe('exempt')
        ->and(collect($bucket['skipped'] ?? []))->not->toContain($member);
});
