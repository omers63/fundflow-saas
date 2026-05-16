<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Services\MasterAccountsInsightsService;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    Account::query()->delete();
    Loan::query()->delete();
    Member::query()->delete();
});

it('returns master account snapshot with fund health metrics', function () {
    Account::factory()->masterFund()->withBalance(50_000)->create();
    Account::factory()->masterCash()->withBalance(10_000)->create();
    Account::factory()->masterExpense()->withBalance(2_000)->create();

    $snapshot = app(MasterAccountsInsightsService::class)->snapshot();

    expect($snapshot)->toHaveKeys(['kpis', 'hero', 'fund_health', 'coverage', 'master_fund'])
        ->and($snapshot['kpis'])->toHaveCount(6)
        ->and($snapshot['master_fund'])->toBe(50_000.0);
});
