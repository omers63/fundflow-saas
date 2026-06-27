<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Services\AccountDetailInsightsService;
use App\Support\Insights\InsightFormatter;
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

it('marks master invest net return red when invested out exceeds returns in', function () {
    $invest = Account::factory()->masterInvest()->withBalance(0)->create();

    Transaction::factory()->for($invest)->create([
        'type' => 'debit',
        'amount' => 1_000,
        'description' => 'External placement (invest out)',
    ]);

    Transaction::factory()->for($invest)->create([
        'type' => 'credit',
        'amount' => 400,
        'description' => 'Proceeds (investment return)',
    ]);

    $snapshot = app(AccountDetailInsightsService::class)->snapshot($invest);
    $netReturnRow = collect($snapshot['context']['panels'][0]['rows'])
        ->firstWhere('label', __('Net return'));

    expect($netReturnRow['value_class'])->toBe('text-rose-600 dark:text-rose-400')
        ->and($snapshot['context']['sixth_kpi']['value_class'])->toBe('text-rose-600 dark:text-rose-400');
});

it('marks master invest net return green when returns in exceed invested out', function () {
    $invest = Account::factory()->masterInvest()->withBalance(0)->create();

    Transaction::factory()->for($invest)->create([
        'type' => 'debit',
        'amount' => 400,
        'description' => 'External placement (invest out)',
    ]);

    Transaction::factory()->for($invest)->create([
        'type' => 'credit',
        'amount' => 1_000,
        'description' => 'Proceeds (investment return)',
    ]);

    $snapshot = app(AccountDetailInsightsService::class)->snapshot($invest);
    $netReturnRow = collect($snapshot['context']['panels'][0]['rows'])
        ->firstWhere('label', __('Net return'));

    expect($netReturnRow['value_class'])->toBe('text-emerald-600 dark:text-emerald-400')
        ->and($snapshot['context']['sixth_kpi']['value_class'])->toBe('text-emerald-600 dark:text-emerald-400');
});

it('sums master expense disbursements into disbursed out', function () {
    $expense = Account::factory()->masterExpense()->withBalance(600)->create();

    Transaction::factory()->for($expense)->create([
        'type' => 'credit',
        'amount' => 1_000,
        'description' => 'Office supplies (reserve funding)',
    ]);

    Transaction::factory()->for($expense)->create([
        'type' => 'debit',
        'amount' => 400,
        'description' => 'Expense disbursement #1 – Rent (expense out)',
    ]);

    $snapshot = app(AccountDetailInsightsService::class)->snapshot($expense);
    $rows = collect($snapshot['context']['panels'][0]['rows'])->keyBy('label');

    expect($rows[__('Disbursed out')]['value'])->toBe(InsightFormatter::money(400))
        ->and($rows[__('Funded in')]['value'])->toBe(InsightFormatter::money(1_000))
        ->and($rows[__('Available')]['value_class'])->toBe('text-emerald-600 dark:text-emerald-400');
});

it('marks master expense available red when disbursed out exceeds funded in', function () {
    $expense = Account::factory()->masterExpense()->withBalance(0)->create();

    Transaction::factory()->for($expense)->create([
        'type' => 'credit',
        'amount' => 500,
        'description' => 'Partial funding (reserve funding)',
    ]);

    Transaction::factory()->for($expense)->create([
        'type' => 'debit',
        'amount' => 800,
        'description' => 'Expense disbursement #2 – Over spend (expense out)',
    ]);

    $snapshot = app(AccountDetailInsightsService::class)->snapshot($expense);
    $availableRow = collect($snapshot['context']['panels'][0]['rows'])
        ->firstWhere('label', __('Available'));

    expect($availableRow['value_class'])->toBe('text-rose-600 dark:text-rose-400')
        ->and($snapshot['context']['sixth_kpi']['value_class'])->toBe('text-rose-600 dark:text-rose-400');
});
