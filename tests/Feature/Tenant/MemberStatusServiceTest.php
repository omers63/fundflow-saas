<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\MemberStatusService;
use App\Support\BusinessDay;
use App\Support\LegacyMemberStatusMapper;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->statuses = app(MemberStatusService::class);
    $this->accounting = app(AccountingService::class);
});

test('freeze with fund cash-out transfers balance and creates pending cash-out request', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'joined_at' => now(),
        'monthly_contribution_amount' => 0,
    ]);
    $this->accounting->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 10_000]);
    Account::masterFund()->update(['balance' => 10_000]);

    $this->accounting->creditMemberFundWithMasterMirror(
        $member->fundAccount,
        1_500,
        'Seed fund',
        '(test)',
        $member,
        now(),
        $member->id,
    );

    $freezeDate = BusinessDay::today()->endOfDay();

    $this->statuses->freeze($member, 'Relocation', $freezeDate, cashOutBalances: true);

    $member->refresh();

    expect($member->status)->toBe('inactive')
        ->and($member->frozen_at?->toDateString())->toBe($freezeDate->toDateString())
        ->and($member->getFundBalance())->toBe(0.0)
        ->and($member->getCashBalance())->toBe(1500.0);

    $cashOut = CashOutRequest::query()
        ->where('member_id', $member->id)
        ->first();

    expect($cashOut)->not->toBeNull()
        ->and((float) $cashOut->amount)->toBe(1500.0)
        ->and($cashOut->status)->toBe('pending');
});

test('freeze cash-out includes existing cash balance with transferred fund', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'joined_at' => now(),
        'monthly_contribution_amount' => 0,
    ]);
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
        1_500,
        'Seed fund',
        '(test)',
        $member,
        now(),
        $member->id,
    );

    $this->statuses->freeze($member, 'Relocation', BusinessDay::today()->endOfDay(), cashOutBalances: true);

    $member->refresh();

    $cashOut = CashOutRequest::query()
        ->where('member_id', $member->id)
        ->first();

    expect($member->getFundBalance())->toBe(0.0)
        ->and($member->getCashBalance())->toBe(2000.0)
        ->and($cashOut)->not->toBeNull()
        ->and((float) $cashOut->amount)->toBe(2000.0);
});

test('freeze cash-out includes cash-only balance when fund is empty', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'joined_at' => now(),
        'monthly_contribution_amount' => 0,
    ]);
    $this->accounting->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 10_000]);

    $this->accounting->creditMemberCashWithMasterMirror(
        $member->cashAccount,
        800,
        'Seed cash',
        '(test)',
        $member,
        now(),
        $member->id,
    );

    $this->statuses->freeze($member, 'Relocation', BusinessDay::today()->endOfDay(), cashOutBalances: true);

    $cashOut = CashOutRequest::query()
        ->where('member_id', $member->id)
        ->first();

    expect($member->fresh()->getCashBalance())->toBe(800.0)
        ->and($cashOut)->not->toBeNull()
        ->and((float) $cashOut->amount)->toBe(800.0);
});

test('freeze cash-out uses balances as of the selected freeze date', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'joined_at' => now(),
        'monthly_contribution_amount' => 0,
    ]);
    $this->accounting->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 10_000]);
    Account::masterFund()->update(['balance' => 10_000]);

    $this->accounting->creditMemberFundWithMasterMirror(
        $member->fundAccount,
        1_000,
        'January fund',
        '(test)',
        $member,
        Carbon::parse('2026-01-10'),
        $member->id,
    );
    $this->accounting->creditMemberFundWithMasterMirror(
        $member->fundAccount,
        500,
        'February fund',
        '(test)',
        $member,
        Carbon::parse('2026-02-01'),
        $member->id,
    );
    $this->accounting->creditMemberCashWithMasterMirror(
        $member->cashAccount,
        300,
        'January cash',
        '(test)',
        $member,
        Carbon::parse('2026-01-12'),
        $member->id,
    );
    $this->accounting->creditMemberCashWithMasterMirror(
        $member->cashAccount,
        200,
        'February cash',
        '(test)',
        $member,
        Carbon::parse('2026-02-05'),
        $member->id,
    );

    $freezeDate = Carbon::parse('2026-01-31')->endOfDay();

    $this->statuses->freeze($member, 'Relocation', $freezeDate, cashOutBalances: true);

    $cashOut = CashOutRequest::query()
        ->where('member_id', $member->id)
        ->first();

    expect($member->fresh()->frozen_at?->toDateString())->toBe('2026-01-31')
        ->and($cashOut)->not->toBeNull()
        ->and((float) $cashOut->amount)->toBe(1300.0)
        ->and($member->fresh()->getFundBalance())->toBe(500.0)
        ->and($member->fresh()->getCashBalance())->toBe(1500.0);
});

test('freeze and unfreeze membership', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'joined_at' => now(),
        'monthly_contribution_amount' => 0,
    ]);

    $freezeDate = BusinessDay::today()->endOfDay();

    $this->statuses->freeze($member, 'Travel abroad', $freezeDate);

    expect($member->fresh()->status)->toBe('inactive')
        ->and($member->fresh()->contribution_cycles_active)->toBeFalse()
        ->and($member->fresh()->frozen_at?->toDateString())->toBe($freezeDate->toDateString());

    $this->statuses->unfreeze($member->fresh());

    expect($member->fresh()->status)->toBe('active')
        ->and($member->fresh()->frozen_at)->toBeNull();
});

test('restore inactive returns active when no arrears', function () {
    $member = Member::factory()->create([
        'status' => 'inactive',
        'frozen_at' => null,
        'contribution_cycles_active' => false,
        'joined_at' => now(),
        'monthly_contribution_amount' => 0,
    ]);

    $this->statuses->restoreInactive($member);

    expect($member->fresh()->status)->toBe('active')
        ->and($member->fresh()->contribution_cycles_active)->toBeTrue();
});

test('guarantor transfer suspend keeps contribution cycles active', function () {
    $member = Member::factory()->create(['status' => 'active']);

    $this->statuses->suspendForGuarantorTransfer($member);

    expect($member->fresh()->status)->toBe('inactive')
        ->and($member->fresh()->frozen_at)->toBeNull()
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
    expect($member->status)->toBe('withdrawn')
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
        ->and($member->fresh()->contribution_cycles_active)->toBeFalse()
        ->and($member->fresh()->payout_frozen_at)->toBeNull();
});

test('legacy inactive import maps to inactive status', function () {
    expect(LegacyMemberStatusMapper::normalize('inactive'))->toBe('inactive');
});

test('legacy delinquent import maps to active status', function () {
    expect(LegacyMemberStatusMapper::normalize('delinquent'))->toBe('active')
        ->and(LegacyMemberStatusMapper::normalize('suspended'))->toBe('inactive')
        ->and(LegacyMemberStatusMapper::normalize('terminated'))->toBe('withdrawn');
});
