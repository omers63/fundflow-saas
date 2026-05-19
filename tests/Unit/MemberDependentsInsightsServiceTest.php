<?php

use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\MemberDependentsInsightsService;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Member::query()->delete();
    User::query()->delete();
});

test('dependents insights returns empty for non parent', function () {
    $user = User::create([
        'name' => 'Solo',
        'email' => 'solo@dependents.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $user->id,
        'member_number' => 'MEM-SOLO',
        'name' => 'Solo',
        'email' => 'solo@dependents.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    expect(app(MemberDependentsInsightsService::class)->snapshot($member))->toBe([]);
});
