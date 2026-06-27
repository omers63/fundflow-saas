<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Transaction;
use App\Support\Insights\MasterInvestNetReturn;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('master invest net return summarizes lifetime invest flows', function () {
    $invest = Account::factory()->masterInvest()->withBalance(250)->create();

    Transaction::factory()->for($invest)->create([
        'type' => 'debit',
        'amount' => 1_000,
        'description' => 'Placement A (invest out)',
    ]);

    Transaction::factory()->for($invest)->create([
        'type' => 'credit',
        'amount' => 400,
        'description' => 'Proceeds A (investment return)',
    ]);

    expect(MasterInvestNetReturn::summarize($invest))->toBe([
        'returns_in' => 400.0,
        'invested_out' => 1_000.0,
        'net_return' => -600.0,
        'is_negative' => true,
    ]);
});
