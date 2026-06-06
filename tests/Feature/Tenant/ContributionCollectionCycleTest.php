<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\DependentCashAllocation;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCollectionCycleService;
use App\Services\ContributionCycleService;
use App\Services\MemberOpeningBalanceService;
use App\Support\ContributionCollectionStatus;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Contribution::query()->delete();
    Member::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
    $this->collection = app(ContributionCollectionCycleService::class);
    $this->cycles = app(ContributionCycleService::class);
});

test('cycle init creates pending contributions with balance snapshot', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Jane Doe',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->credit($member->cashAccount, 200, 'Seed cash'),
    );

    [$month, $year] = $this->cycles->currentOpenPeriod();
    $created = $this->collection->initializeOpenPeriod($month, $year);

    expect($created)->toBe(1);

    $contribution = Contribution::query()->forPeriod($month, $year)->where('member_id', $member->id)->first();

    expect($contribution)->not->toBeNull()
        ->and($contribution->collection_status)->toBe(ContributionCollectionStatus::PENDING)
        ->and((float) $contribution->amount_due)->toBe(500.0)
        ->and((float) $contribution->cycle_open_cash_balance)->toBe(200.0);
});

test('partial debit leaves partially pending until deposit covers shortfall', function () {
    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();

    $member = Member::create([
        'member_number' => 'MEM-0002',
        'name' => 'John Doe',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::create($year, $month, 1)->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->credit($member->cashAccount, 200, 'Seed cash'),
    );

    [$month, $year] = $this->cycles->currentOpenPeriod();
    $this->collection->initializeOpenPeriod($month, $year);

    $contribution = Contribution::query()->forPeriod($month, $year)->where('member_id', $member->id)->firstOrFail();

    expect($this->collection->attemptCollection($contribution))->toBe('partial')
        ->and($contribution->fresh()->collection_status)->toBe(ContributionCollectionStatus::PARTIALLY_PENDING)
        ->and((float) $contribution->fresh()->amount_collected)->toBe(200.0);

    $this->accounting->credit($member->cashAccount, 400, 'Deposit');

    expect($contribution->fresh()->collection_status)->toBe(ContributionCollectionStatus::COLLECTED)
        ->and($contribution->fresh()->status)->toBe('posted');
});

test('close window flags overdue contributions', function () {
    [$month, $year] = [(int) now()->subMonth()->month, (int) now()->subMonth()->year];

    $member = Member::create([
        'member_number' => 'MEM-0003',
        'name' => 'Late Member',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 100,
        'amount_due' => 100,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    $flagged = $this->collection->closeCollectionWindow($month, $year);

    expect($flagged)->toBe(1)
        ->and(Contribution::query()->forPeriod($month, $year)->first()->collection_status)
        ->toBe(ContributionCollectionStatus::OVERDUE);
});

test('member cash credit fully settles oldest arrear including late fees before the next cycle', function () {
    $jan = now()->subMonths(2);
    $feb = now()->subMonth();

    $member = Member::create([
        'member_number' => 'MEM-0013',
        'name' => 'Late Fee Arrear Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => $jan->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate((int) $jan->month, (int) $jan->year),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $jan->copy()->endOfMonth(),
        'is_late' => true,
        'late_fee_amount' => 50,
    ]);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate((int) $feb->month, (int) $feb->year),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $feb->copy()->endOfMonth(),
        'is_late' => true,
    ]);

    $this->accounting->credit($member->cashAccount, 550, 'Deposit');

    $janContribution = Contribution::query()
        ->forPeriod((int) $jan->month, (int) $jan->year)
        ->where('member_id', $member->id)
        ->first();

    $febContribution = Contribution::query()
        ->forPeriod((int) $feb->month, (int) $feb->year)
        ->where('member_id', $member->id)
        ->first();

    expect($janContribution->status)->toBe('posted')
        ->and($febContribution->status)->toBe('pending')
        ->and((float) $member->cashAccount->fresh()->balance)->toBe(0.0);
});

test('member cash credit auto-collects oldest contribution arrear before newer cycles', function () {
    $older = now()->subMonths(2);
    $newer = now()->subMonth();

    $member = Member::create([
        'member_number' => 'MEM-0010',
        'name' => 'Arrear Member',
        'monthly_contribution_amount' => 100,
        'joined_at' => $older->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate((int) $older->month, (int) $older->year),
        'amount' => 100,
        'amount_due' => 100,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $older->copy()->endOfMonth(),
        'is_late' => true,
    ]);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate((int) $newer->month, (int) $newer->year),
        'amount' => 100,
        'amount_due' => 100,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $newer->copy()->endOfMonth(),
        'is_late' => true,
    ]);

    $this->accounting->credit($member->cashAccount, 200, 'Deposit');

    $olderContribution = Contribution::query()
        ->forPeriod((int) $older->month, (int) $older->year)
        ->where('member_id', $member->id)
        ->first();

    $newerContribution = Contribution::query()
        ->forPeriod((int) $newer->month, (int) $newer->year)
        ->where('member_id', $member->id)
        ->first();

    expect($olderContribution->status)->toBe('posted')
        ->and($olderContribution->is_late)->toBeTrue()
        ->and($newerContribution->status)->toBe('posted')
        ->and($newerContribution->is_late)->toBeTrue();
});

test('member cash credit auto-collects missing arrear periods oldest first', function () {
    $older = now()->subMonths(2);
    $newer = now()->subMonth();

    $member = Member::create([
        'member_number' => 'MEM-0012',
        'name' => 'Missing Arrear Member',
        'monthly_contribution_amount' => 100,
        'joined_at' => $older->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    expect(Contribution::query()->where('member_id', $member->id)->count())->toBe(0);

    $this->accounting->credit($member->cashAccount, 250, 'Deposit');

    $olderContribution = Contribution::query()
        ->forPeriod((int) $older->month, (int) $older->year)
        ->where('member_id', $member->id)
        ->first();

    $newerContribution = Contribution::query()
        ->forPeriod((int) $newer->month, (int) $newer->year)
        ->where('member_id', $member->id)
        ->first();

    expect($olderContribution)->not->toBeNull()
        ->and($olderContribution->status)->toBe('posted')
        ->and($newerContribution)->not->toBeNull()
        ->and($newerContribution->status)->toBe('posted');
});

test('direct credit to a dependent cash account auto-collects overdue contributions', function () {
    $older = now()->subMonths(2);

    $parent = Member::create([
        'member_number' => 'MEM-P02',
        'name' => 'Parent Two',
        'email' => 'parent-two@example.com',
        'monthly_contribution_amount' => 100,
        'joined_at' => $older->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($parent);

    $dependent = Member::create([
        'member_number' => 'MEM-D02',
        'name' => 'Child Two',
        'email' => 'child-two@example.com',
        'parent_member_id' => $parent->id,
        'household_email' => 'parent-two@example.com',
        'monthly_contribution_amount' => 80,
        'joined_at' => $older->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dependent);

    Contribution::create([
        'member_id' => $dependent->id,
        'period' => Contribution::periodDate((int) $older->month, (int) $older->year),
        'amount' => 80,
        'amount_due' => 80,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $older->copy()->endOfMonth(),
        'is_late' => true,
    ]);

    $this->accounting->credit($dependent->cashAccount, 120, 'Direct dependent credit');

    $dependentContribution = Contribution::query()
        ->where('member_id', $dependent->id)
        ->forPeriod((int) $older->month, (int) $older->year)
        ->first();

    expect($dependentContribution->status)->toBe('posted')
        ->and($dependentContribution->is_late)->toBeTrue()
        ->and((float) $dependent->cashAccount->fresh()->balance)->toBeLessThan(120.0);
});

test('parent allocation to dependent triggers oldest-first arrear settlement on dependent cash', function () {
    $jan = now()->subMonths(2);
    $feb = now()->subMonth();

    $parent = Member::create([
        'member_number' => 'MEM-P03',
        'name' => 'Parent Allocate',
        'email' => 'parent-allocate@example.com',
        'monthly_contribution_amount' => 100,
        'joined_at' => $jan->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($parent);

    $dependent = Member::create([
        'member_number' => 'MEM-D03',
        'name' => 'Child Allocate',
        'email' => 'child-allocate@example.com',
        'parent_member_id' => $parent->id,
        'household_email' => 'parent-allocate@example.com',
        'monthly_contribution_amount' => 80,
        'joined_at' => $jan->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dependent);

    AccountingService::withoutMemberCashCollection(fn () => $this->accounting->credit(
        $parent->cashAccount,
        200,
        'Parent funding',
    ));

    Contribution::create([
        'member_id' => $dependent->id,
        'period' => Contribution::periodDate((int) $jan->month, (int) $jan->year),
        'amount' => 80,
        'amount_due' => 80,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $jan->copy()->endOfMonth(),
        'is_late' => true,
        'late_fee_amount' => 20,
    ]);

    Contribution::create([
        'member_id' => $dependent->id,
        'period' => Contribution::periodDate((int) $feb->month, (int) $feb->year),
        'amount' => 80,
        'amount_due' => 80,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $feb->copy()->endOfMonth(),
        'is_late' => true,
    ]);

    $this->cycles->applyDependentAllocationForParentForPeriod(
        $parent->fresh(),
        (int) $jan->month,
        (int) $jan->year,
    );

    $this->collection->onMemberCashIncreased($dependent->fresh());

    $janContribution = Contribution::query()
        ->where('member_id', $dependent->id)
        ->forPeriod((int) $jan->month, (int) $jan->year)
        ->first();

    $febContribution = Contribution::query()
        ->where('member_id', $dependent->id)
        ->forPeriod((int) $feb->month, (int) $feb->year)
        ->first();

    expect($janContribution->status)->toBe('posted')
        ->and($febContribution->status)->toBe('pending')
        ->and((float) $dependent->cashAccount->fresh()->balance)->toBe(0.0);
});

test('import cut-off cash does not collect the in-window current cycle before its deadline', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));

    [$currentMonth, $currentYear] = app(ContributionCycleService::class)->currentOpenPeriod();

    $may = Carbon::create(2026, 5, 1);

    $member = Member::create([
        'member_number' => 'MEM-CUTOFF-2',
        'name' => 'Cutoff Current Window Member',
        'email' => 'cutoff-window@example.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => $may->copy()->startOfMonth(),
        'contribution_arrears_cutoff_date' => $may->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate((int) $may->month, (int) $may->year),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $may->copy()->endOfMonth(),
        'is_late' => true,
    ]);

    app(MemberOpeningBalanceService::class)->postOpeningBalances(
        $member,
        600,
        0,
        $may->copy()->startOfMonth(),
        'IMPORT_CUTOFF',
    );

    $mayContribution = Contribution::query()
        ->where('member_id', $member->id)
        ->forPeriod((int) $may->month, (int) $may->year)
        ->first();

    $currentContribution = Contribution::query()
        ->where('member_id', $member->id)
        ->forPeriod($currentMonth, $currentYear)
        ->first();

    expect($mayContribution->status)->toBe('posted')
        ->and($currentContribution)->toBeNull();

    Carbon::setTestNow();
});

test('import cut-off cash posting triggers oldest-first arrear collection', function () {
    $arrearMonth = now()->subMonths(2);

    $member = Member::create([
        'member_number' => 'MEM-CUTOFF-1',
        'name' => 'Cutoff Cash Member',
        'email' => 'cutoff-cash@example.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => $arrearMonth->copy()->startOfMonth(),
        'contribution_arrears_cutoff_date' => $arrearMonth->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate((int) $arrearMonth->month, (int) $arrearMonth->year),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $arrearMonth->copy()->endOfMonth(),
        'is_late' => true,
        'late_fee_amount' => 50,
    ]);

    app(MemberOpeningBalanceService::class)->postOpeningBalances(
        $member,
        550,
        0,
        $arrearMonth->copy()->startOfMonth(),
        'IMPORT_CUTOFF',
    );

    $contribution = Contribution::query()
        ->where('member_id', $member->id)
        ->forPeriod((int) $arrearMonth->month, (int) $arrearMonth->year)
        ->first();

    expect($contribution->status)->toBe('posted')
        ->and($contribution->is_late)->toBeTrue()
        ->and((float) $member->cashAccount->fresh()->balance)->toBe(0.0);
});

test('late fee tier accrual does not post for periods before import arrears cut-off', function () {
    $cutoff = Carbon::parse('2024-06-01');
    $preCutoff = Carbon::parse('2024-03-01');

    $member = Member::create([
        'member_number' => 'MEM-NOFEE',
        'name' => 'No Pre Cutoff Late Fee',
        'email' => 'nofee@example.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2018-01-01'),
        'contribution_arrears_cutoff_date' => $cutoff,
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate((int) $preCutoff->month, (int) $preCutoff->year),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $preCutoff->copy()->addMonth()->endOfMonth(),
        'is_late' => true,
    ]);

    $masterFeesBefore = (float) Account::query()->where('is_master', true)->where('type', 'fees')->value('balance');

    expect($this->collection->applyLateFeeTierForContribution($contribution))->toBeFalse()
        ->and($this->collection->applyNightlyLateFees())->toBe(0)
        ->and((float) Account::query()->where('is_master', true)->where('type', 'fees')->value('balance'))
        ->toBe($masterFeesBefore);
});

test('dismiss pre-cutoff pending contributions reverses posted late fees from master fees', function () {
    $cutoff = Carbon::parse('2024-06-01');
    $preCutoff = Carbon::parse('2024-03-01');

    $member = Member::create([
        'member_number' => 'MEM-DISMISS',
        'name' => 'Dismiss Pre Cutoff',
        'email' => 'dismiss@example.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2018-01-01'),
        'contribution_arrears_cutoff_date' => $cutoff,
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate((int) $preCutoff->month, (int) $preCutoff->year),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::LATE_T1,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $preCutoff->copy()->addMonth()->endOfMonth(),
        'is_late' => true,
        'late_fee_amount' => 50,
        'late_fee_tier' => 1,
    ]);

    AccountingService::withoutMemberCashCollection(function () use ($contribution): void {
        app(AccountingService::class)->postContributionLateFee($contribution, 50);
    });

    expect((float) Account::query()->where('is_master', true)->where('type', 'fees')->value('balance'))->toBe(50.0);

    $contributionId = $contribution->id;

    $dismissed = $this->collection->dismissPreCutoffPendingContributions($member);

    expect($dismissed)->toBe(1)
        ->and(Contribution::query()->find($contributionId))->toBeNull()
        ->and(Contribution::query()->where('member_id', $member->id)->where('status', 'pending')->count())->toBe(0)
        ->and((float) Account::query()->where('is_master', true)->where('type', 'fees')->value('balance'))->toBe(0.0);
});

test('auto-collection skips pending cycles before import arrears cut-off', function () {
    $cutoff = now()->subMonths(3)->copy()->startOfMonth();
    $preCutoff = $cutoff->copy()->subYears(2);
    $preCutoffMonth = (int) $preCutoff->month;
    $preCutoffYear = (int) $preCutoff->year;
    $cutoffMonth = (int) $cutoff->month;
    $cutoffYear = (int) $cutoff->year;

    $member = Member::create([
        'member_number' => 'MEM-PRECUTOFF',
        'name' => 'Pre Cutoff Pending Member',
        'email' => 'precutoff@example.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(5)->startOfMonth(),
        'contribution_arrears_cutoff_date' => $cutoff,
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate($preCutoffMonth, $preCutoffYear),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $preCutoff->copy()->endOfMonth(),
        'is_late' => true,
    ]);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate($cutoffMonth, $cutoffYear),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $cutoff->copy()->endOfMonth(),
        'is_late' => true,
        'late_fee_amount' => 50,
    ]);

    app(MemberOpeningBalanceService::class)->postOpeningBalances(
        $member,
        550,
        0,
        $cutoff,
        'IMPORT_CUTOFF',
    );

    $legacyContribution = Contribution::query()
        ->where('member_id', $member->id)
        ->forPeriod($preCutoffMonth, $preCutoffYear)
        ->first();

    $cutoffContribution = Contribution::query()
        ->where('member_id', $member->id)
        ->forPeriod($cutoffMonth, $cutoffYear)
        ->first();

    expect($legacyContribution->status)->toBe('pending')
        ->and($cutoffContribution->status)->toBe('posted');
});

test('dependent allocation requires parent cash for the full period amount not a partial top-up', function () {
    $jan = now()->subMonths(2);

    $parent = Member::create([
        'member_number' => 'MEM-P05',
        'name' => 'Parent Full Allocate',
        'email' => 'parent-full@example.com',
        'monthly_contribution_amount' => 100,
        'joined_at' => $jan->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($parent);

    $dependent = Member::create([
        'member_number' => 'MEM-D05',
        'name' => 'Child Full Allocate',
        'email' => 'child-full@example.com',
        'parent_member_id' => $parent->id,
        'household_email' => 'parent-full@example.com',
        'monthly_contribution_amount' => 100,
        'joined_at' => $jan->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dependent);

    AccountingService::withoutMemberCashCollection(fn () => $this->accounting->credit(
        $parent->cashAccount,
        90,
        'Limited parent cash',
    ));

    Contribution::create([
        'member_id' => $dependent->id,
        'period' => Contribution::periodDate((int) $jan->month, (int) $jan->year),
        'amount' => 100,
        'amount_due' => 100,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $jan->copy()->endOfMonth(),
        'is_late' => true,
    ]);

    $result = $this->cycles->applyDependentAllocationForParentForPeriod(
        $parent->fresh(),
        (int) $jan->month,
        (int) $jan->year,
    );

    expect($result['transfers'])->toBe(0)
        ->and(Contribution::query()->where('member_id', $dependent->id)->first()->status)->toBe('pending');
});

test('dependent allocation transfers only the cash shortfall for a cycle', function () {
    $jan = now()->subMonths(2);

    $parent = Member::create([
        'member_number' => 'MEM-P06',
        'name' => 'Parent Shortfall',
        'email' => 'parent-shortfall@example.com',
        'monthly_contribution_amount' => 100,
        'joined_at' => $jan->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($parent);

    $dependent = Member::create([
        'member_number' => 'MEM-D06',
        'name' => 'Child Shortfall',
        'email' => 'child-shortfall@example.com',
        'parent_member_id' => $parent->id,
        'household_email' => 'parent-shortfall@example.com',
        'monthly_contribution_amount' => 100,
        'joined_at' => $jan->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dependent);

    AccountingService::withoutMemberCashCollection(fn () => $this->accounting->credit(
        $parent->cashAccount,
        100,
        'Parent funding',
    ));

    AccountingService::withoutMemberCashCollection(fn () => $this->accounting->credit(
        $dependent->cashAccount,
        30,
        'Partial dependent cash',
    ));

    Contribution::create([
        'member_id' => $dependent->id,
        'period' => Contribution::periodDate((int) $jan->month, (int) $jan->year),
        'amount' => 100,
        'amount_due' => 100,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $jan->copy()->endOfMonth(),
        'is_late' => true,
    ]);

    $result = $this->cycles->applyDependentAllocationForParentForPeriod(
        $parent->fresh(),
        (int) $jan->month,
        (int) $jan->year,
    );

    expect($result['transfers'])->toBe(1)
        ->and((float) $parent->cashAccount->fresh()->balance)->toBe(30.0)
        ->and((float) $dependent->cashAccount->fresh()->balance)->toBe(100.0);
});

test('allocation cycle options only include periods the parent can fully fund', function () {
    $jan = now()->subMonths(2);

    $parent = Member::create([
        'member_number' => 'MEM-P07',
        'name' => 'Parent Options',
        'email' => 'parent-options@example.com',
        'monthly_contribution_amount' => 100,
        'joined_at' => $jan->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($parent);

    $dependent = Member::create([
        'member_number' => 'MEM-D07',
        'name' => 'Child Options',
        'email' => 'child-options@example.com',
        'parent_member_id' => $parent->id,
        'household_email' => 'parent-options@example.com',
        'monthly_contribution_amount' => 100,
        'joined_at' => $jan->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dependent);

    AccountingService::withoutMemberCashCollection(fn () => $this->accounting->credit(
        $parent->cashAccount,
        90,
        'Limited parent cash',
    ));

    Contribution::create([
        'member_id' => $dependent->id,
        'period' => Contribution::periodDate((int) $jan->month, (int) $jan->year),
        'amount' => 100,
        'amount_due' => 100,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $jan->copy()->endOfMonth(),
        'is_late' => true,
    ]);

    expect($this->cycles->allocationCycleSelectOptionsForParent($parent->fresh()))->toBe([])
        ->and($this->cycles->shouldShowDependentAllocationAction($parent->fresh()))->toBeFalse();
});

test('household dependent allocation for a cycle runs before parent contribution settlement', function () {
    $jan = now()->subMonths(2);

    $parent = Member::create([
        'member_number' => 'MEM-P04',
        'name' => 'Parent Priority',
        'email' => 'parent-priority@example.com',
        'monthly_contribution_amount' => 100,
        'joined_at' => $jan->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($parent);

    $dependent = Member::create([
        'member_number' => 'MEM-D04',
        'name' => 'Child Priority',
        'email' => 'child-priority@example.com',
        'parent_member_id' => $parent->id,
        'household_email' => 'parent-priority@example.com',
        'monthly_contribution_amount' => 80,
        'joined_at' => $jan->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dependent);

    Contribution::create([
        'member_id' => $parent->id,
        'period' => Contribution::periodDate((int) $jan->month, (int) $jan->year),
        'amount' => 100,
        'amount_due' => 100,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $jan->copy()->endOfMonth(),
        'is_late' => true,
    ]);

    Contribution::create([
        'member_id' => $dependent->id,
        'period' => Contribution::periodDate((int) $jan->month, (int) $jan->year),
        'amount' => 80,
        'amount_due' => 80,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $jan->copy()->endOfMonth(),
        'is_late' => true,
    ]);

    AccountingService::withoutMemberCashCollection(fn () => $this->accounting->credit(
        $parent->cashAccount,
        150,
        'Parent funding',
    ));

    $this->collection->onMemberCashIncreased($parent->fresh());

    $parentContribution = Contribution::query()
        ->where('member_id', $parent->id)
        ->forPeriod((int) $jan->month, (int) $jan->year)
        ->first();

    $dependentContribution = Contribution::query()
        ->where('member_id', $dependent->id)
        ->forPeriod((int) $jan->month, (int) $jan->year)
        ->first();

    expect($dependentContribution->status)->toBe('posted')
        ->and($parentContribution->status)->toBe('pending')
        ->and((float) $parent->cashAccount->fresh()->balance)->toBe(0.0);
});

test('household settlement advances to later cycles with partial parent funding', function () {
    $jan = now()->subMonths(2);
    $feb = now()->subMonth();

    $parent = Member::create([
        'member_number' => 'MEM-P06',
        'name' => 'Parent Cycle Order',
        'email' => 'parent-cycle@example.com',
        'monthly_contribution_amount' => 100,
        'joined_at' => $jan->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($parent);

    $dependent = Member::create([
        'member_number' => 'MEM-D06',
        'name' => 'Child Cycle Order',
        'email' => 'child-cycle@example.com',
        'parent_member_id' => $parent->id,
        'household_email' => 'parent-cycle@example.com',
        'monthly_contribution_amount' => 80,
        'joined_at' => $jan->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dependent);

    foreach ([$jan, $feb] as $period) {
        Contribution::create([
            'member_id' => $parent->id,
            'period' => Contribution::periodDate((int) $period->month, (int) $period->year),
            'amount' => 100,
            'amount_due' => 100,
            'amount_collected' => 0,
            'status' => 'pending',
            'collection_status' => ContributionCollectionStatus::OVERDUE,
            'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
            'overdue_since' => $period->copy()->endOfMonth(),
            'is_late' => true,
        ]);

        Contribution::create([
            'member_id' => $dependent->id,
            'period' => Contribution::periodDate((int) $period->month, (int) $period->year),
            'amount' => 80,
            'amount_due' => 80,
            'amount_collected' => 0,
            'status' => 'pending',
            'collection_status' => ContributionCollectionStatus::OVERDUE,
            'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
            'overdue_since' => $period->copy()->endOfMonth(),
            'is_late' => true,
        ]);
    }

    AccountingService::withoutMemberCashCollection(fn () => $this->accounting->credit(
        $parent->cashAccount,
        260,
        'Parent deposit',
    ));

    $this->collection->onMemberCashIncreased($parent->fresh());

    $parentJan = Contribution::query()
        ->where('member_id', $parent->id)
        ->forPeriod((int) $jan->month, (int) $jan->year)
        ->first();
    $parentFeb = Contribution::query()
        ->where('member_id', $parent->id)
        ->forPeriod((int) $feb->month, (int) $feb->year)
        ->first();
    $dependentJan = Contribution::query()
        ->where('member_id', $dependent->id)
        ->forPeriod((int) $jan->month, (int) $jan->year)
        ->first();
    $dependentFeb = Contribution::query()
        ->where('member_id', $dependent->id)
        ->forPeriod((int) $feb->month, (int) $feb->year)
        ->first();

    expect(DependentCashAllocation::query()->count())->toBe(2)
        ->and($parentJan->status)->toBe('posted')
        ->and($dependentJan->status)->toBe('posted')
        ->and($parentFeb->status)->toBe('pending')
        ->and($dependentFeb->status)->toBe('posted')
        ->and((float) $parent->cashAccount->fresh()->balance)->toBe(0.0)
        ->and((float) $dependent->cashAccount->fresh()->balance)->toBe(0.0);
});

test('parent cash credit allocates to dependents and collects their contributions oldest first', function () {
    $older = now()->subMonths(2);

    $parent = Member::create([
        'member_number' => 'MEM-P01',
        'name' => 'Parent Household',
        'email' => 'parent-household@example.com',
        'monthly_contribution_amount' => 100,
        'joined_at' => $older->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($parent);

    $dependent = Member::create([
        'member_number' => 'MEM-D01',
        'name' => 'Child Household',
        'email' => 'child-household@example.com',
        'parent_member_id' => $parent->id,
        'household_email' => 'parent-household@example.com',
        'monthly_contribution_amount' => 80,
        'joined_at' => $older->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dependent);

    Contribution::create([
        'member_id' => $dependent->id,
        'period' => Contribution::periodDate((int) $older->month, (int) $older->year),
        'amount' => 80,
        'amount_due' => 80,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $older->copy()->endOfMonth(),
        'is_late' => true,
    ]);

    $this->accounting->credit($parent->cashAccount, 300, 'Parent deposit');

    $dependentContribution = Contribution::query()
        ->where('member_id', $dependent->id)
        ->forPeriod((int) $older->month, (int) $older->year)
        ->first();

    expect($dependentContribution->status)->toBe('posted')
        ->and($dependentContribution->is_late)->toBeTrue()
        ->and((float) $parent->cashAccount->fresh()->balance)->toBeLessThan(200.0);
});

test('collecting an overdue contribution keeps the late flag after payment', function () {
    [$month, $year] = [(int) now()->subMonth()->month, (int) now()->subMonth()->year];

    $member = Member::create([
        'member_number' => 'MEM-0011',
        'name' => 'Late Paid Member',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->credit($member->cashAccount, 150, 'Seed cash'),
    );

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 100,
        'amount_due' => 100,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => now()->subDays(15),
        'is_late' => true,
        'late_fee_tier' => 1,
        'late_fee_amount' => 10,
    ]);

    expect($this->collection->attemptCollection($contribution))->toBe('collected')
        ->and($contribution->fresh()->status)->toBe('posted')
        ->and($contribution->fresh()->is_late)->toBeTrue();
});

test('onMemberCashIncreased does not drive member cash below zero', function () {
    $older = now()->subMonths(3)->startOfMonth();
    $newer = now()->subMonths(2)->startOfMonth();

    $member = Member::create([
        'member_number' => 'MEM-NONNEG',
        'name' => 'Non Negative Cash',
        'email' => 'nonneg@example.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => $older->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    foreach ([$older, $newer] as $period) {
        Contribution::create([
            'member_id' => $member->id,
            'period' => Contribution::periodDate((int) $period->month, (int) $period->year),
            'amount' => 500,
            'amount_due' => 500,
            'amount_collected' => 0,
            'status' => 'pending',
            'collection_status' => ContributionCollectionStatus::OVERDUE,
            'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
            'overdue_since' => $period->copy()->endOfMonth(),
            'is_late' => true,
        ]);
    }

    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->credit($member->cashAccount, 300, 'Deposit'),
    );

    $this->collection->onMemberCashIncreased($member->fresh());

    expect((float) $member->cashAccount->fresh()->balance)->toBeGreaterThanOrEqual(0.0)
        ->and((float) $member->cashAccount->fresh()->balance)->toBeLessThanOrEqual(300.0);

    $posted = Contribution::query()
        ->where('member_id', $member->id)
        ->where('status', 'posted')
        ->count();

    expect($posted)->toBe(0);
});
