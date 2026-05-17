<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\ContributionService;
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
