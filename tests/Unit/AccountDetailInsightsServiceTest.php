<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Services\AccountDetailInsightsService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
});

it('returns account detail snapshot with kpis and recent ledger', function () {
    $member = Member::factory()->create();
    $account = Account::factory()->for($member)->cash()->withBalance(1_500)->create();

    Transaction::factory()->for($account)->create([
        'type' => 'credit',
        'amount' => 500,
        'transacted_at' => Carbon::now()->subDays(2),
    ]);

    $snapshot = app(AccountDetailInsightsService::class)->snapshot($account);

    expect($snapshot)
        ->toHaveKeys(['hero', 'kpis', 'recent', 'balance', 'sparkline', 'context'])
        ->and($snapshot['kpis'])->toHaveCount(6)
        ->and($snapshot['balance'])->toBe(1500.0)
        ->and($snapshot['account']['id'])->toBe($account->id);
});

it('includes member context panels for member cash accounts', function () {
    $member = Member::factory()->create();
    $cash = Account::factory()->for($member)->cash()->withBalance(100)->create();
    Account::factory()->for($member)->fund()->withBalance(200)->create();

    $snapshot = app(AccountDetailInsightsService::class)->snapshot($cash);

    expect($snapshot['context']['panels'])->not->toBeEmpty()
        ->and($snapshot['context']['sixth_kpi'])->not->toBeNull();
});
