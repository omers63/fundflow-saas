<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\MemberStatusService;
use App\Support\LegacyMemberStatusMapper;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->statuses = app(MemberStatusService::class);
    $this->accounting = app(AccountingService::class);
});

test('freeze and unfreeze membership', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'joined_at' => now(),
        'monthly_contribution_amount' => 0,
    ]);

    $this->statuses->freeze($member, 'Travel abroad');

    expect($member->fresh()->status)->toBe('inactive')
        ->and($member->fresh()->contribution_cycles_active)->toBeFalse();

    $this->statuses->unfreeze($member->fresh());

    expect($member->fresh()->status)->toBe('active');
});

test('restore suspended returns active when no arrears', function () {
    $member = Member::factory()->create([
        'status' => 'suspended',
        'contribution_cycles_active' => false,
        'joined_at' => now(),
        'monthly_contribution_amount' => 0,
    ]);

    $this->statuses->restoreSuspended($member);

    expect($member->fresh()->status)->toBe('active')
        ->and($member->fresh()->contribution_cycles_active)->toBeTrue();
});

test('guarantor transfer suspend keeps contribution cycles active', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $this->statuses->suspendForGuarantorTransfer($member);

    expect($member->fresh()->status)->toBe('suspended')
        ->and($member->fresh()->contribution_cycles_active)->toBeTrue();
});

test('terminate freezes payout and reinstate clears balances', function () {
    $member = Member::factory()->create(['status' => 'active']);
    $this->accounting->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 10_000]);
    Account::masterFund()->update(['balance' => 10_000]);

    $this->accounting->creditMemberCashWithMasterMirror(
        $member->cashAccount,
        500,
        'Seed cash',
        '(test)',
        $member,
        now(),
        $member->id,
    );
    $this->accounting->creditMemberFundWithMasterMirror(
        $member->fundAccount,
        800,
        'Seed fund',
        '(test)',
        $member,
        now(),
        $member->id,
    );

    $this->statuses->terminate($member, 'Policy violation');

    $member->refresh();
    expect($member->status)->toBe('terminated')
        ->and($member->payout_frozen_at)->not->toBeNull();

    $this->statuses->reinstate($member, 'Board approved return');

    $member->refresh();
    expect($member->status)->toBe('active')
        ->and($member->payout_frozen_at)->toBeNull()
        ->and($member->getCashBalance())->toBe(0.0)
        ->and($member->getFundBalance())->toBe(0.0);
});

test('admin can withdraw member directly', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $this->statuses->withdraw($member, 'Voluntary exit');

    expect($member->fresh()->status)->toBe('withdrawn')
        ->and($member->fresh()->contribution_cycles_active)->toBeFalse();
});

test('legacy inactive import maps to inactive status', function () {
    expect(LegacyMemberStatusMapper::normalize('inactive'))->toBe('inactive');
});
