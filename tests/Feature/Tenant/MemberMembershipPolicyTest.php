<?php

declare(strict_types=1);

use App\Models\Tenant\Member;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\MemberMembershipPolicy;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('member statuses are simplified to active inactive withdrawn', function () {
    expect(Member::STATUSES)->toBe([
        'active',
        'inactive',
        'withdrawn',
    ])
        ->and(Member::PORTAL_BLOCKED_STATUSES)->toContain('inactive', 'withdrawn');
});

test('membership policy allows active members to contribute', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
        'contribution_cycles_active' => true,
        'joined_at' => now()->subYear(),
    ]);

    $delinquency = Mockery::mock(LoanDelinquencyService::class);
    $delinquency->shouldReceive('isDelinquent')->with($member)->andReturn(false);
    $delinquency->shouldReceive('memberHasArrearsExcludingOpenCycle')->andReturn(false);

    $policy = new MemberMembershipPolicy($delinquency);

    expect($policy->canAdminContribute($member))->toBeTrue()
        ->and($policy->canAccessPortal($member))->toBeTrue();
});

test('membership policy blocks portal for delinquent active members', function () {
    $policy = app(MemberMembershipPolicy::class);
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
    ]);

    $delinquency = Mockery::mock(LoanDelinquencyService::class);
    $delinquency->shouldReceive('isDelinquent')->with($member)->andReturn(true);
    $delinquency->shouldReceive('memberHasArrearsExcludingOpenCycle')->andReturn(true);

    $policy = new MemberMembershipPolicy($delinquency);

    expect($policy->canAccessPortal($member))->toBeFalse()
        ->and($policy->canApplyForLoan($member))->toBeFalse();
});

test('membership policy allows inactive members with contribution cycle flag', function () {
    $policy = app(MemberMembershipPolicy::class);
    $member = Member::factory()->make([
        'status' => 'inactive',
        'frozen_at' => null,
        'monthly_contribution_amount' => 1000,
        'contribution_cycles_active' => true,
    ]);

    expect($policy->canParticipateInContributionCycles($member))->toBeTrue()
        ->and($policy->canAccessPortal($member))->toBeFalse();
});

test('withdrawn members with frozen payout cannot receive payout', function () {
    $policy = app(MemberMembershipPolicy::class);
    $member = Member::factory()->make([
        'status' => 'withdrawn',
        'payout_frozen_at' => now(),
    ]);

    expect($policy->canReceivePayout($member))->toBeFalse()
        ->and($policy->canRequestCashOut($member))->toBeFalse();
});

test('withdrawn members may cash out when payout is not frozen', function () {
    $policy = app(MemberMembershipPolicy::class);
    $member = Member::factory()->make([
        'status' => 'withdrawn',
        'payout_frozen_at' => null,
    ]);

    expect($policy->canRequestCashOut($member))->toBeTrue();
});
