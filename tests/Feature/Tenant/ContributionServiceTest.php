<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\AccountingService;
use App\Services\ContributionService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->accounting = app(AccountingService::class);
    $this->service = app(ContributionService::class);

    Account::query()->delete();
    Member::query()->delete();
    Setting::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
});

test('record contribution creates a pending contribution', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Test User',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $contribution = $this->service->recordContribution($member, '2026-05-01');

    expect($contribution->status)->toBe('pending');
    expect($contribution->amount)->toBe('5000.00');
    expect($contribution->member_id)->toBe($member->id);
});

test('posting contribution debits member cash and credits member fund and master fund', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Test User',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $member->cashAccount->update(['balance' => 5000]);

    $contribution = $this->service->recordContribution($member, '2026-05-01');
    $this->service->postContribution($contribution);

    $contribution->refresh();
    expect($contribution->status)->toBe('posted');
    expect($contribution->posted_at)->not->toBeNull();

    $member->refresh();
    expect($member->cashAccount->fresh()->balance)->toBe('0.00');
    expect($member->fundAccount->fresh()->balance)->toBe('5000.00');
    expect(Account::masterFund()->balance)->toBe('5000.00');
});

test('contribution cycle uses configurable start day', function () {
    Setting::set('contribution', 'cycle_start_day', '10');

    expect($this->service->getCycleStartDay())->toBe(10);

    $range = $this->service->getCycleDateRange(now()->startOfMonth());
    expect($range['start']->day)->toBe(10);
});

test('default cycle start day is 6', function () {
    expect($this->service->getCycleStartDay())->toBe(6);
});

test('generate monthly contributions creates entries for all active members', function () {
    foreach (range(1, 3) as $i) {
        Member::create([
            'member_number' => "MEM-000{$i}",
            'name' => "Member {$i}",
            'monthly_contribution_amount' => 1000 * $i,
            'joined_at' => now()->subYear(),
            'status' => 'active',
        ]);
    }

    $count = $this->service->generateMonthlyContributions('2026-05-01');

    expect($count)->toBe(3);
    expect(Contribution::where('period', '2026-05-01')->count())->toBe(3);
});

test('duplicate contributions are not generated', function () {
    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'Test User',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $this->service->generateMonthlyContributions('2026-05-01');
    $count = $this->service->generateMonthlyContributions('2026-05-01');

    expect($count)->toBe(0);
    expect(Contribution::where('member_id', $member->id)->where('period', '2026-05-01')->count())->toBe(1);
});
