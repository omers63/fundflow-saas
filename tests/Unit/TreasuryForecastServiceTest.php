<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Services\TreasuryForecastService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    FundPosting::query()->delete();
    CashOutRequest::query()->delete();
    Member::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 10_000, 'is_master' => true]);
});

test('treasury forecast aggregates pending deposits and cash-outs', function () {
    $member = Member::factory()->create();

    FundPosting::create([
        'member_id' => $member->id,
        'amount' => 2_500,
        'status' => 'pending',
        'posting_date' => now()->toDateString(),
    ]);

    CashOutRequest::create([
        'member_id' => $member->id,
        'amount' => 1_000,
        'status' => 'pending',
    ]);

    $forecast = app(TreasuryForecastService::class)->snapshot();

    expect($forecast['master_cash'])->toBe(10_000.0)
        ->and($forecast['pending_deposit_count'])->toBe(1)
        ->and($forecast['pending_deposit_amount'])->toBe(2_500.0)
        ->and($forecast['pending_cash_out_count'])->toBe(1)
        ->and($forecast['pending_cash_out_amount'])->toBe(1_000.0)
        ->and($forecast['pending_net_amount'])->toBe(1_500.0)
        ->and($forecast['projected_available_cash'])->toBe(11_500.0)
        ->and($forecast['tone'])->toBe('warning');
});

test('treasury forecast returns success tone when no pending pressure', function () {
    $forecast = app(TreasuryForecastService::class)->snapshot();

    expect($forecast['pending_deposit_count'])->toBe(0)
        ->and($forecast['pending_cash_out_count'])->toBe(0)
        ->and($forecast['pending_net_amount'])->toBe(0.0)
        ->and($forecast['projected_available_cash'])->toBe(10_000.0)
        ->and($forecast['tone'])->toBe('success');
});
