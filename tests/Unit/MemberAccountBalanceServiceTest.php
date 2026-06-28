<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\MemberAccountBalanceService;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->balances = app(MemberAccountBalanceService::class);
    $this->accounting = app(AccountingService::class);
});

test('member account balance service returns ledger balance as of date', function () {
    $member = Member::factory()->create();
    $this->accounting->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 5_000]);
    Account::masterFund()->update(['balance' => 5_000]);

    $this->accounting->creditMemberFundWithMasterMirror(
        $member->fundAccount,
        1_000,
        'Early fund',
        '(test)',
        $member,
        Carbon::parse('2026-01-05'),
        $member->id,
    );
    $this->accounting->creditMemberFundWithMasterMirror(
        $member->fundAccount,
        250,
        'Later fund',
        '(test)',
        $member,
        Carbon::parse('2026-02-01'),
        $member->id,
    );

    $asOf = Carbon::parse('2026-01-31');

    expect($this->balances->balanceAtDate($member, 'fund', $asOf))->toBe(1000.0)
        ->and($this->balances->positiveFreezeCashOutBalances($member, $asOf))->toBe([
                'fund' => 1000.0,
                'cash' => 0.0,
                'total' => 1000.0,
            ]);
});

test('member account balance service uses live account balances for todays freeze date', function () {
    $member = Member::factory()->create();
    $this->accounting->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 5_000]);
    Account::masterFund()->update(['balance' => 5_000]);

    $member->cashAccount->update(['balance' => 750]);
    $member->fundAccount->update(['balance' => 1250]);

    $today = BusinessDay::today();

    expect($this->balances->positiveFreezeCashOutBalances($member, $today))->toBe([
        'fund' => 1250.0,
        'cash' => 750.0,
        'total' => 2000.0,
    ]);
});
