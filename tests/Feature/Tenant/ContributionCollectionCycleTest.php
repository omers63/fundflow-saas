<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCollectionCycleService;
use App\Services\ContributionCycleService;
use App\Support\ContributionCollectionStatus;
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
    $this->accounting->credit($member->cashAccount, 200, 'Seed cash');

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
    $member = Member::create([
        'member_number' => 'MEM-0002',
        'name' => 'John Doe',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $this->accounting->credit($member->cashAccount, 200, 'Seed cash');

    [$month, $year] = $this->cycles->currentOpenPeriod();
    $this->collection->initializeOpenPeriod($month, $year);

    $contribution = Contribution::query()->forPeriod($month, $year)->where('member_id', $member->id)->firstOrFail();

    expect($this->collection->attemptCollection($contribution))->toBe('partial')
        ->and($contribution->fresh()->collection_status)->toBe(ContributionCollectionStatus::PARTIALLY_PENDING)
        ->and((float) $contribution->fresh()->amount_collected)->toBe(200.0);

    $this->accounting->credit($member->cashAccount, 300, 'Deposit');

    expect($this->collection->attemptCollection($contribution->fresh()))->toBe('collected')
        ->and($contribution->fresh()->collection_status)->toBe(ContributionCollectionStatus::COLLECTED)
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
