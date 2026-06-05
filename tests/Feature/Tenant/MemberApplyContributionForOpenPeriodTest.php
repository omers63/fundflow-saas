<?php

declare(strict_types=1);

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));

    $this->initializeTenancy();

    Contribution::query()->delete();
    Member::query()->delete();
    User::query()->delete();
});

afterEach(function (): void {
    Carbon::setTestNow();
});

test('member contribution can be applied for the open period using service helper', function () {
    $accounting = app(AccountingService::class);
    $cycles = app(ContributionCycleService::class);

    $memberUser = User::create([
        'name' => 'Service Contribution Member',
        'email' => 'service-contribution-member@test.com',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $memberUser->id,
        'member_number' => 'MEM-SC-001',
        'name' => 'Service Contribution Member',
        'email' => 'service-contribution-member@test.com',
        'monthly_contribution_amount' => 1_000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 2_000]);

    [$month, $year] = $cycles->currentOpenPeriod();

    $result = $cycles->applyContributionForMemberForPeriod($member, $month, $year);

    $contribution = Contribution::findForMemberPeriod($member->id, $month, $year);

    expect($result)->toBe('applied')
        ->and($contribution)->not->toBeNull()
        ->and($contribution->status)->toBe('posted')
        ->and((float) $contribution->amount)->toBe(1_000.0);
});
