<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\DependentCashAllocation;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCollectionCycleService;
use App\Services\ContributionCycleService;
use App\Services\DependentAllocationService;
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
    Carbon::setTestNow(Carbon::parse('2026-06-15 10:00:00'));

    $arrearMonth = Carbon::create(2026, 4, 1)->startOfMonth();

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

test('dependent allocation does not re-fund after the cycle amount is fulfilled', function () {
    $jan = now()->subMonths(2);

    $parent = Member::create([
        'member_number' => 'MEM-P-ONCE',
        'name' => 'Parent Once',
        'email' => 'parent-once@example.com',
        'monthly_contribution_amount' => 0,
        'joined_at' => $jan->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($parent);

    $dependent = Member::create([
        'member_number' => 'MEM-D-ONCE',
        'name' => 'Child Once',
        'email' => 'child-once@example.com',
        'parent_member_id' => $parent->id,
        'household_email' => 'parent-once@example.com',
        'monthly_contribution_amount' => 200,
        'joined_at' => $jan->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dependent);

    Contribution::create([
        'member_id' => $dependent->id,
        'period' => Contribution::periodDate((int) $jan->month, (int) $jan->year),
        'amount' => 200,
        'amount_due' => 200,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $jan->copy()->endOfMonth(),
        'is_late' => true,
    ]);

    AccountingService::withoutMemberCashCollection(fn () => $this->accounting->credit(
        $parent->cashAccount,
        500,
        'Parent funding',
    ));

    $first = $this->cycles->applyDependentAllocationForParentForPeriod(
        $parent->fresh(),
        (int) $jan->month,
        (int) $jan->year,
    );

    // Drain dependent cash so a naive cash-shortfall check would try again.
    AccountingService::withoutMemberCashCollection(fn () => $this->accounting->debit(
        $dependent->cashAccount,
        200,
        'Spend allocated cash',
    ));

    AccountingService::withoutMemberCashCollection(fn () => $this->accounting->credit(
        $parent->cashAccount,
        200,
        'More parent cash',
    ));

    $second = $this->cycles->applyDependentAllocationForParentForPeriod(
        $parent->fresh(),
        (int) $jan->month,
        (int) $jan->year,
    );

    expect($first['transfers'])->toBe(1)
        ->and($second['transfers'])->toBe(0)
        ->and(DependentCashAllocation::query()->where('dependent_member_id', $dependent->id)->count())->toBe(1)
        ->and((float) $this->cycles->dependentAllocatedAmountForPeriod($dependent->fresh(), (int) $jan->month, (int) $jan->year))->toBe(200.0)
        ->and($this->cycles->dependentAllocationFulfilledForPeriod($dependent->fresh(), (int) $jan->month, (int) $jan->year))->toBeTrue()
        ->and((float) $dependent->cashAccount->fresh()->balance)->toBe(0.0);
});

test('dependent allocation tops up when cycle dues increase before fulfillment', function () {
    $jan = now()->subMonths(2);

    $parent = Member::create([
        'member_number' => 'MEM-P-TOP',
        'name' => 'Parent Topup',
        'email' => 'parent-topup@example.com',
        'monthly_contribution_amount' => 0,
        'joined_at' => $jan->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($parent);

    $dependent = Member::create([
        'member_number' => 'MEM-D-TOP',
        'name' => 'Child Topup',
        'email' => 'child-topup@example.com',
        'parent_member_id' => $parent->id,
        'household_email' => 'parent-topup@example.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => $jan->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dependent);

    $contribution = Contribution::create([
        'member_id' => $dependent->id,
        'period' => Contribution::periodDate((int) $jan->month, (int) $jan->year),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::OVERDUE,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'overdue_since' => $jan->copy()->endOfMonth(),
        'is_late' => true,
    ]);

    AccountingService::withoutMemberCashCollection(fn () => $this->accounting->credit(
        $parent->cashAccount,
        2000,
        'Parent funding',
    ));

    $first = $this->cycles->applyDependentAllocationForParentForPeriod(
        $parent->fresh(),
        (int) $jan->month,
        (int) $jan->year,
    );

    expect($first['transfers'])->toBe(1)
        ->and((float) $this->cycles->dependentAllocatedAmountForPeriod($dependent->fresh(), (int) $jan->month, (int) $jan->year))->toBe(500.0);

    Member::withoutSelfAllocationGuard(fn () => $dependent->update([
        'monthly_contribution_amount' => 1500,
    ]));
    $contribution->update([
        'amount' => 1500,
        'amount_due' => 1500,
    ]);

    $second = $this->cycles->applyDependentAllocationForParentForPeriod(
        $parent->fresh(),
        (int) $jan->month,
        (int) $jan->year,
    );

    $allocation = DependentCashAllocation::query()
        ->where('dependent_member_id', $dependent->id)
        ->where('allocation_month', (int) $jan->month)
        ->where('allocation_year', (int) $jan->year)
        ->first();

    expect($second['transfers'])->toBe(1)
        ->and(DependentCashAllocation::query()->where('dependent_member_id', $dependent->id)->count())->toBe(1)
        ->and((float) $allocation->amount)->toBe(1500.0)
        ->and((float) $dependent->cashAccount->fresh()->balance)->toBe(1500.0)
        ->and($this->cycles->dependentAllocationFulfilledForPeriod($dependent->fresh(), (int) $jan->month, (int) $jan->year))->toBeTrue();
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

test('parent cash credit allocates to dependent in loan repayment cycle for EMI dues', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $parent = Member::create([
        'member_number' => 'MEM-P-EMI',
        'name' => 'Parent EMI Household',
        'email' => 'parent-emi@example.com',
        'monthly_contribution_amount' => 100,
        'joined_at' => Carbon::parse('2026-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($parent);

    $dependent = Member::create([
        'member_number' => 'MEM-D-EMI',
        'name' => 'Child EMI Borrower',
        'email' => 'child-emi@example.com',
        'parent_member_id' => $parent->id,
        'household_email' => 'parent-emi@example.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2026-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dependent);

    $loan = Loan::create([
        'member_id' => $dependent->id,
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

    foreach ([
        ['month' => 6, 'year' => 2026, 'number' => 1],
        ['month' => 7, 'year' => 2026, 'number' => 2],
        ['month' => 8, 'year' => 2026, 'number' => 3],
        ['month' => 9, 'year' => 2026, 'number' => 4],
    ] as $period) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $period['number'],
            'amount' => 1000,
            'due_date' => Carbon::create($period['year'], $period['month'], 15),
            'status' => 'pending',
        ]);
    }

    $installment = LoanInstallment::query()
        ->where('loan_id', $loan->id)
        ->where('installment_number', 1)
        ->firstOrFail();

    expect($dependent->fresh()->isExemptFromContributions(6, 2026))->toBeTrue()
        ->and($this->cycles->dependentAllocationShortfallForPeriod($dependent->fresh(), 6, 2026))->toBe(1000.0);

    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->credit($parent->cashAccount, 1000, 'Parent deposit'),
    );

    $allocation = $this->cycles->applyDependentAllocationForParentForPeriod($parent->fresh(), 6, 2026);

    expect($allocation['transfers'])->toBe(1)
        ->and(DependentCashAllocation::query()->where('dependent_member_id', $dependent->id)->exists())->toBeTrue()
        ->and((float) $dependent->cashAccount->fresh()->balance)->toBe(1000.0);

    $this->collection->onMemberCashIncreased($parent->fresh());

    expect($installment->fresh()->status)->toBe('paid')
        ->and((float) $parent->cashAccount->fresh()->balance)->toBe(0.0)
        ->and((float) $dependent->cashAccount->fresh()->balance)->toBe(0.0);

    Carbon::setTestNow();
});

test('dependent allocation shortfall counts EMI dues when contributions are exempt', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $dependent = Member::create([
        'member_number' => 'MEM-D-MIX',
        'name' => 'Child Borrower',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dependent);

    $loan = Loan::create([
        'member_id' => $dependent->id,
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

    foreach ([
        ['month' => 6, 'year' => 2026, 'number' => 1],
        ['month' => 7, 'year' => 2026, 'number' => 2],
    ] as $period) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $period['number'],
            'amount' => 1000,
            'due_date' => Carbon::create($period['year'], $period['month'], 15),
            'status' => 'pending',
        ]);
    }

    expect($dependent->fresh()->isExemptFromContributions(6, 2026))->toBeTrue()
        ->and($this->cycles->dependentCycleDuesForPeriod($dependent->fresh(), 6, 2026))->toBe(1000.0)
        ->and($this->cycles->dependentAllocationShortfallForPeriod($dependent->fresh(), 6, 2026))->toBe(1000.0);

    Carbon::setTestNow();
});

test('self-funded dependent skips parent fund allocation for contributions and emi', function () {
    $period = now()->subMonth();

    $parent = Member::create([
        'member_number' => 'MEM-P-ZERO',
        'name' => 'Parent Zero Alloc',
        'email' => 'parent-zero@example.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => $period->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($parent);

    $activeDependent = Member::create([
        'member_number' => 'MEM-D-ACTIVE',
        'name' => 'Included Child',
        'email' => 'included-child@example.com',
        'parent_member_id' => $parent->id,
        'household_email' => 'parent-zero@example.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => $period->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($activeDependent);

    $excludedDependent = Member::create([
        'member_number' => 'MEM-D-ZERO',
        'name' => 'Excluded Child',
        'email' => 'excluded-child@example.com',
        'parent_member_id' => $parent->id,
        'household_email' => 'parent-zero@example.com',
        'monthly_contribution_amount' => 500,
        'exclude_from_household_contribution_funding' => true,
        'joined_at' => $period->copy()->startOfMonth(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($excludedDependent);

    foreach ([$parent, $activeDependent, $excludedDependent] as $member) {
        Contribution::create([
            'member_id' => $member->id,
            'period' => Contribution::periodDate((int) $period->month, (int) $period->year),
            'amount' => (float) $member->monthly_contribution_amount,
            'amount_due' => (float) $member->monthly_contribution_amount,
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
        500,
        'Parent deposit',
    ));

    $result = $this->cycles->applyDependentAllocationForParentForPeriod(
        $parent->fresh(),
        (int) $period->month,
        (int) $period->year,
    );

    expect($result['allocated_dependent_ids'])->toContain($activeDependent->id)
        ->and($result['allocated_dependent_ids'])->not->toContain($excludedDependent->id)
        ->and(DependentCashAllocation::query()->where('dependent_member_id', $excludedDependent->id)->exists())->toBeFalse()
        ->and(DependentCashAllocation::query()->where('dependent_member_id', $activeDependent->id)->exists())->toBeTrue()
        ->and((int) $excludedDependent->fresh()->monthly_contribution_amount)->toBe(500)
        ->and($this->cycles->dependentCycleDuesForPeriod($excludedDependent->fresh(), (int) $period->month, (int) $period->year))->toBe(0.0)
        ->and($this->cycles->requiredCollectionCashForMemberPeriod($excludedDependent->fresh(), (int) $period->month, (int) $period->year))->toBe(500.0);
});

test('self-funded dependent loan emi is excluded from parent cycle allocation', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $parent = Member::create([
        'member_number' => 'MEM-P-EMI',
        'name' => 'Parent EMI',
        'email' => 'parent-emi@example.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($parent);

    $dependent = Member::create([
        'member_number' => 'MEM-D-EMI',
        'name' => 'Self Funded Borrower',
        'email' => 'self-funded-borrower@example.com',
        'parent_member_id' => $parent->id,
        'household_email' => 'parent-emi@example.com',
        'monthly_contribution_amount' => 500,
        'exclude_from_household_contribution_funding' => true,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dependent);

    $loan = Loan::create([
        'member_id' => $dependent->id,
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

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-06-15'),
        'status' => 'pending',
    ]);

    expect($dependent->fresh()->isFundedByParent())->toBeFalse()
        ->and($this->cycles->dependentCycleDuesForPeriod($dependent->fresh(), 6, 2026))->toBe(0.0)
        ->and($this->cycles->dependentAllocationShortfallForPeriod($dependent->fresh(), 6, 2026))->toBe(0.0);

    Carbon::setTestNow();
});

test('mid-cycle dependent allocation increase syncs pending due and funds the new amount', function () {
    Carbon::setTestNow(Carbon::parse('2025-11-10'));

    [$month, $year] = $this->cycles->currentOpenPeriod();
    $joinedAt = Carbon::create($year, $month, 1)->startOfMonth();

    $parent = Member::create([
        'member_number' => 'MEM-P-MID',
        'name' => 'Parent Mid Cycle',
        'email' => 'parent-mid@example.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => $joinedAt,
        'contribution_arrears_cutoff_date' => $joinedAt,
        'status' => 'active',
        'contribution_cycles_active' => false,
    ]);
    $this->accounting->createMemberAccounts($parent);

    $dependent = Member::create([
        'member_number' => 'MEM-D-MID',
        'name' => 'Dependent Mid Cycle',
        'email' => 'dependent-mid@example.com',
        'parent_member_id' => $parent->id,
        'household_email' => 'parent-mid@example.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => $joinedAt,
        'contribution_arrears_cutoff_date' => $joinedAt,
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dependent);

    Contribution::create([
        'member_id' => $dependent->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    AccountingService::withoutMemberCashCollection(fn () => $this->accounting->credit(
        $parent->cashAccount,
        2000,
        'Parent deposit',
    ));

    app(DependentAllocationService::class)->changeAllocation(
        parent: $parent,
        dependent: $dependent,
        newAmount: 1500,
    );

    $pending = Contribution::findForMemberPeriod($dependent->id, $month, $year);

    expect((int) $dependent->fresh()->monthly_contribution_amount)->toBe(1500)
        ->and((float) $pending->amount_due)->toBe(1500.0)
        ->and((float) $pending->amount)->toBe(1500.0);

    $results = [
        'applied' => collect(),
        'insufficient' => collect(),
        'skipped' => collect(),
    ];

    $this->collection->applyHouseholdContributionsForPeriod(
        $parent->fresh(),
        collect([$dependent->fresh()]),
        $month,
        $year,
        $results,
    );

    $allocation = DependentCashAllocation::query()
        ->where('dependent_member_id', $dependent->id)
        ->where('allocation_month', $month)
        ->where('allocation_year', $year)
        ->first();

    $contribution = Contribution::findForMemberPeriod($dependent->id, $month, $year);

    expect($allocation)->not->toBeNull()
        ->and((float) $allocation->amount)->toBe(1500.0)
        ->and($contribution->status)->toBe('posted')
        ->and((float) $contribution->amount_due)->toBe(1500.0)
        ->and((float) $contribution->amount_collected)->toBe(1500.0);

    Carbon::setTestNow();
});

test('open-cycle override due above standing is preserved when standing increases', function () {
    [$month, $year] = $this->cycles->currentOpenPeriod();

    $member = Member::create([
        'member_number' => 'MEM-OVR',
        'name' => 'Override Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 5000,
        'amount_due' => 5000,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    Member::withoutSelfAllocationGuard(fn () => $member->update([
        'monthly_contribution_amount' => 1500,
    ]));

    $contribution = Contribution::findForMemberPeriod($member->id, $month, $year);

    expect((float) $contribution->amount_due)->toBe(5000.0)
        ->and((float) $this->cycles->requiredCollectionCashForMemberPeriod($member->fresh(), $month, $year))->toBe(5000.0);
});
