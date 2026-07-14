<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\LoanService;
use App\Support\ContributionCollectionStatus;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    app()->setLocale('en');
    $this->accounting = app(AccountingService::class);
    $this->service = app(LoanService::class);

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    Contribution::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
});

function seedPostedContributionsThroughOpenPeriod(Member $member): void
{
    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $cursor = $member->joined_at?->copy()->startOfMonth() ?? now()->startOfMonth();

    while ($cursor->lte(Carbon::create($openYear, $openMonth, 1)->endOfMonth())) {
        $month = (int) $cursor->month;
        $year = (int) $cursor->year;

        if (
            (float) $member->monthly_contribution_amount > 0
            && ! $member->isExemptFromContributions($month, $year)
            && ! Contribution::query()->where('member_id', $member->id)->forPeriod($month, $year)->exists()
        ) {
            Contribution::create([
                'member_id' => $member->id,
                'period' => Contribution::periodDate($month, $year),
                'amount' => $member->monthly_contribution_amount,
                'amount_due' => $member->monthly_contribution_amount,
                'amount_collected' => $member->monthly_contribution_amount,
                'status' => 'posted',
                'collection_status' => ContributionCollectionStatus::COLLECTED,
                'posted_at' => $cursor->copy()->endOfMonth(),
                'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
                'is_late' => false,
            ]);
        }

        $cursor->addMonthNoOverflow();
    }
}

test('member with clean standing remains eligible', function () {
    $member = Member::create([
        'member_number' => 'MEM-CLEAN-'.uniqid(),
        'name' => 'Clean Standing Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->fundAccount()->update(['balance' => 20000]);
    seedPostedContributionsThroughOpenPeriod($member);

    $result = $this->service->checkEligibility($member->fresh());

    expect($result['eligible'])->toBeTrue();
});

test('member with only the open cycle unposted remains loan eligible', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    $member = Member::create([
        'member_number' => 'MEM-PEND',
        'name' => 'Pending Cycle Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->fundAccount()->update(['balance' => 20000]);

    [$openMonth, $openYear] = app(ContributionCycleService::class)->currentOpenPeriod();
    $cursor = $member->joined_at->copy()->startOfMonth();
    $openStart = Carbon::create($openYear, $openMonth, 1)->startOfMonth();

    while ($cursor->lt($openStart)) {
        Contribution::create([
            'member_id' => $member->id,
            'period' => Contribution::periodDate((int) $cursor->month, (int) $cursor->year),
            'amount' => 5000,
            'amount_due' => 5000,
            'amount_collected' => 5000,
            'status' => 'posted',
            'collection_status' => ContributionCollectionStatus::COLLECTED,
            'posted_at' => $cursor->copy()->endOfMonth(),
            'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        ]);

        $cursor->addMonthNoOverflow();
    }

    $result = $this->service->checkEligibility($member);

    expect($result['eligible'])->toBeTrue();

    Carbon::setTestNow();
});

test('member with a past period collection still open is not loan eligible', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    $member = Member::create([
        'member_number' => 'MEM-PAST-PEND',
        'name' => 'Past Pending Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->fundAccount()->update(['balance' => 20000]);

    foreach (range(1, 5) as $month) {
        Contribution::create([
            'member_id' => $member->id,
            'period' => Contribution::periodDate($month, 2026),
            'amount' => 5000,
            'amount_due' => 5000,
            'amount_collected' => $month === 4 ? 0 : 5000,
            'status' => $month === 4 ? 'pending' : 'posted',
            'collection_status' => $month === 4
                ? ContributionCollectionStatus::OVERDUE
                : ContributionCollectionStatus::COLLECTED,
            'posted_at' => $month === 4 ? null : Carbon::create(2026, $month, 10),
            'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        ]);
    }

    $result = $this->service->checkEligibility($member);

    expect($result['eligible'])->toBeFalse()
        ->and($result['reasons'][0])->toContain('unsettled contribution collection');

    Carbon::setTestNow();
});

test('member with contribution arrears is not eligible', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    $member = Member::create([
        'member_number' => 'MEM-ARR',
        'name' => 'Arrears Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->fundAccount()->update(['balance' => 20000]);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(5, 2026),
        'amount' => 5000,
        'amount_due' => 5000,
        'amount_collected' => 5000,
        'status' => 'posted',
        'collection_status' => ContributionCollectionStatus::COLLECTED,
        'posted_at' => Carbon::create(2026, 5, 10),
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    $result = $this->service->checkEligibility($member);

    expect($result['eligible'])->toBeFalse()
        ->and($result['reasons'][0])->toContain('arrears');

    Carbon::setTestNow();
});

test('member with three consecutive late cycles is not eligible', function () {
    Carbon::setTestNow(Carbon::create(2026, 6, 15));

    $member = Member::create([
        'member_number' => 'MEM-LATE',
        'name' => 'Late Cycles Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::create(2024, 12, 1),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->fundAccount()->update(['balance' => 20000]);

    $cursor = Carbon::create(2024, 12, 1);
    while ($cursor->lte(Carbon::create(2026, 6, 1))) {
        $month = (int) $cursor->month;
        $year = (int) $cursor->year;
        $isLate = $year === 2026 && in_array($month, [3, 4, 5], true);

        Contribution::create([
            'member_id' => $member->id,
            'period' => Contribution::periodDate($month, $year),
            'amount' => 1000,
            'amount_due' => 1000,
            'amount_collected' => 1000,
            'status' => 'posted',
            'collection_status' => ContributionCollectionStatus::COLLECTED,
            'posted_at' => Carbon::create($year, $month, $isLate ? 20 : 7),
            'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
            'is_late' => $isLate,
        ]);

        $cursor->addMonthNoOverflow();
    }

    $result = $this->service->checkEligibility($member);

    expect($result['eligible'])->toBeFalse()
        ->and($result['reasons'][0])->toContain('consecutive late');

    Carbon::setTestNow();
});

test('loan settlement threshold cooldown cycles round up threshold slice over emi', function () {
    $loan = Loan::make([
        'amount' => 10000,
        'amount_approved' => 10000,
        'monthly_repayment' => 1000,
        'settlement_threshold' => 0.16,
    ]);

    expect($loan->settlementThresholdCooldownCycles())->toBe(2);
});

test('member remains ineligible until settlement threshold waiting period ends', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    $member = Member::create([
        'member_number' => 'MEM-EARLY-SETTLE-'.uniqid(),
        'name' => 'Recent Early Settler',
        'monthly_contribution_amount' => 5000,
        'joined_at' => Carbon::create(2020, 1, 1),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->fundAccount()->update(['balance' => 20000]);
    seedPostedContributionsThroughOpenPeriod($member);

    Loan::create([
        'member_id' => $member->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'amount_disbursed' => 10000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 1000,
        'total_repaid' => 10000,
        'settlement_threshold' => 0.16,
        'status' => 'early_settled',
        'applied_at' => Carbon::create(2025, 1, 1),
        'approved_at' => Carbon::create(2025, 1, 2),
        'disbursed_at' => Carbon::create(2025, 1, 3),
        'settled_at' => Carbon::create(2026, 4, 15),
    ]);

    $result = $this->service->checkEligibility($member->fresh());

    expect($result['eligible'])->toBeFalse()
        ->and($result['reasons'][0])->toContain('settlement threshold waiting period');

    Carbon::setTestNow(Carbon::create(2026, 6, 20));
    seedPostedContributionsThroughOpenPeriod($member->fresh());

    $resultAfterCooldown = $this->service->checkEligibility($member->fresh());

    expect($resultAfterCooldown['eligible'])->toBeTrue();

    Carbon::setTestNow();
});

test('member with active loan after partial early settlement remains ineligible', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    $member = Member::create([
        'member_number' => 'MEM-PARTIAL-SETTLE-'.uniqid(),
        'name' => 'Partial Early Settler',
        'monthly_contribution_amount' => 5000,
        'joined_at' => Carbon::create(2020, 1, 1),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->fundAccount()->update(['balance' => 20000]);
    seedPostedContributionsThroughOpenPeriod($member);

    Loan::create([
        'member_id' => $member->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'amount_disbursed' => 10000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 1000,
        'total_repaid' => 1000,
        'status' => 'active',
        'applied_at' => Carbon::create(2025, 1, 1),
        'approved_at' => Carbon::create(2025, 1, 2),
        'disbursed_at' => Carbon::create(2025, 1, 3),
    ]);

    $result = $this->service->checkEligibility($member->fresh());

    expect($result['eligible'])->toBeFalse()
        ->and($result['reasons'][0])->toContain('loan(s) in progress');

    Carbon::setTestNow();
});
