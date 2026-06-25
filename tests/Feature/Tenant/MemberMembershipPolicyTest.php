<?php

declare(strict_types=1);

use App\Models\Tenant\Member;
use App\Support\MemberMembershipPolicy;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('member statuses include inactive as distinct from withdrawn', function () {
    expect(Member::STATUSES)->toBe([
        'active',
        'inactive',
        'delinquent',
        'suspended',
        'withdrawn',
        'terminated',
    ])
        ->and(Member::PORTAL_BLOCKED_STATUSES)->toContain('inactive', 'delinquent', 'terminated');
});

test('membership policy allows delinquent admin contributions', function () {
    $policy = app(MemberMembershipPolicy::class);
    $member = Member::factory()->make([
        'status' => 'delinquent',
        'monthly_contribution_amount' => 1000,
        'contribution_cycles_active' => true,
    ]);

    expect($policy->canAdminContribute($member))->toBeTrue()
        ->and($policy->canAccessPortal($member))->toBeFalse();
});

test('membership policy allows suspended members with contribution cycle flag', function () {
    $policy = app(MemberMembershipPolicy::class);
    $member = Member::factory()->make([
        'status' => 'suspended',
        'monthly_contribution_amount' => 1000,
        'contribution_cycles_active' => true,
    ]);

    expect($policy->canParticipateInContributionCycles($member))->toBeTrue()
        ->and($policy->canAccessPortal($member))->toBeFalse();
});

test('terminated members cannot receive payout while frozen', function () {
    $policy = app(MemberMembershipPolicy::class);
    $member = Member::factory()->make([
        'status' => 'terminated',
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
