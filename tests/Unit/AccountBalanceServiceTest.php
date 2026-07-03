<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Transaction;
use App\Services\AccountBalanceService;
use App\Services\AccountingService;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Account::query()->delete();
    Transaction::query()->delete();

    $this->balances = app(AccountBalanceService::class);
    $this->accounting = app(AccountingService::class);
});

test('account balance service returns master fund balance as of date from ledger lines', function () {
    $masterFund = Account::factory()->masterFund()->withBalance(0)->create();
    $asOf = Carbon::parse('2026-03-15 16:00:00');

    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->credit($masterFund, 5_000, 'Opening fund', null, Carbon::parse('2026-03-10 09:00:00')),
    );

    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->debit($masterFund, 1_200, 'Later outflow', null, Carbon::parse('2026-03-20 09:00:00')),
    );

    expect($this->balances->balanceAtDate($masterFund, $asOf))->toBe(5_000.0)
        ->and($this->balances->masterFundBalanceAtDate($asOf))->toBe(5_000.0)
        ->and($this->balances->masterFundBalanceAtDate(Carbon::parse('2026-03-20 18:00:00')))->toBe(3_800.0);
});

test('account balance service uses live master fund balance for business today', function () {
    $masterFund = Account::factory()->masterFund()->withBalance(12_345.67)->create();

    expect($this->balances->masterFundBalanceAtDate(BusinessDay::today()))->toBe(12_345.67);
});
