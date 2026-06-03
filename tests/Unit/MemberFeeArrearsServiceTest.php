<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Services\AccountingService;
use App\Services\MemberFeeArrearsService;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    Contribution::query()->delete();
    MembershipApplication::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 10_000, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
});

test('total fee arrears sums subscription shortfall from required and transferred amounts', function () {
    $member = Member::create([
        'member_number' => 'FEE-AR-01',
        'name' => 'Subscription Arrears',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    MembershipApplication::create([
        'name' => $member->name,
        'email' => 'sub-arrears-'.uniqid('', true).'@test.com',
        'member_id' => $member->id,
        'application_type' => 'new',
        'status' => 'approved',
        'membership_fee_amount' => 200,
        'membership_fee_required_amount' => 500,
    ]);

    expect(app(MemberFeeArrearsService::class)->totalFeeArrears($member))->toBe(300.0);
});

test('total fee arrears reads legacy subscription arrears from rejection reason when amounts are unset', function () {
    $member = Member::create([
        'member_number' => 'FEE-AR-02',
        'name' => 'Legacy Arrears',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    MembershipApplication::create([
        'name' => $member->name,
        'email' => 'legacy-arrears-'.uniqid('', true).'@test.com',
        'member_id' => $member->id,
        'application_type' => 'renew',
        'status' => 'approved',
        'rejection_reason' => __('Subscription fee arrears: :amount', ['amount' => number_format(75, 2)]),
    ]);

    expect(app(MemberFeeArrearsService::class)->totalFeeArrears($member))->toBe(75.0);
});

test('total fee arrears includes outstanding contribution late fees after partial collection', function () {
    $member = Member::create([
        'member_number' => 'FEE-AR-03',
        'name' => 'Late Fee Arrears',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 500]);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => now()->startOfMonth(),
        'amount' => 500,
        'status' => 'posted',
        'posted_at' => now(),
        'late_fee_amount' => 100,
    ]);

    AccountingService::withoutMemberCashCollection(function () use ($accounting, $contribution): void {
        $accounting->postContributionLateFee($contribution, 40);
    });

    expect(app(MemberFeeArrearsService::class)->totalFeeArrears($member))->toBe(60.0);
});
