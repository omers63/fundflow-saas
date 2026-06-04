<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\ContributionService;
use App\Support\ContributionCollectionStatus;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->service = app(ContributionService::class);
    $this->accounting = app(AccountingService::class);

    Account::query()->delete();
    Member::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
});

test('apply for period posts contribution when cash is sufficient', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Test User',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 2000]);

    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();
    $bucket = [];
    $outcome = $this->service->applyForPeriod($member, $month, $year, $bucket);

    expect($outcome)->toBe('applied');
    expect(Contribution::query()->where('member_id', $member->id)->forPeriod($month, $year)->where('status', 'posted')->exists())->toBeTrue();
    expect($member->fresh()->getCashBalance())->toBeLessThan(2000);
});

test('apply for period collects an existing pending contribution row', function () {
    $member = Member::create([
        'member_number' => 'MEM-0002',
        'name' => 'Pending Row User',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 2000]);

    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();

    $pending = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 1000,
        'amount_due' => 1000,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
    ]);

    $bucket = [];
    $outcome = $this->service->applyForPeriod($member, $month, $year, $bucket);

    expect($outcome)->toBe('applied')
        ->and($pending->fresh()->status)->toBe('posted');
});

test('apply for period works when import cut-off would block auto-collection', function () {
    Carbon::setTestNow(Carbon::create(2026, 5, 20));

    $member = Member::create([
        'member_number' => 'MEM-CUTOFF',
        'name' => 'Cutoff User',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'contribution_arrears_cutoff_date' => Carbon::create(2026, 5, 1),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 2000]);

    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();
    $bucket = [];
    $outcome = $this->service->applyForPeriod($member, $month, $year, $bucket);

    expect($outcome)->toBe('applied');

    Carbon::setTestNow();
});

test('apply for period succeeds after a soft-deleted pending contribution blocked the period', function () {
    $member = Member::create([
        'member_number' => 'MEM-SOFT-DEL',
        'name' => 'Soft Deleted Row',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 2000]);

    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();

    $pending = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 1000,
        'amount_due' => 1000,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
    ]);

    $pending->delete();

    $bucket = [];
    $outcome = $this->service->applyForPeriod($member, $month, $year, $bucket);

    expect($outcome)->toBe('applied')
        ->and(Contribution::query()->where('member_id', $member->id)->forPeriod($month, $year)->posted()->exists())->toBeTrue();
});

test('apply for period repairs collected contribution that was never posted', function () {
    $member = Member::create([
        'member_number' => 'MEM-COLLECTED',
        'name' => 'Collected Not Posted',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 2000]);

    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();

    $broken = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate($month, $year),
        'amount' => 1000,
        'amount_due' => 1000,
        'amount_collected' => 1000,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::COLLECTED,
        'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
    ]);

    $bucket = [];
    $outcome = $this->service->applyForPeriod($member, $month, $year, $bucket);

    expect($outcome)->toBe('applied')
        ->and($broken->fresh()->status)->toBe('posted');
});
