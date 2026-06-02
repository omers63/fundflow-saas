<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Services\MemberAccountsInsightsService;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    Transaction::query()->delete();
    Account::query()->delete();
    Member::query()->delete();
});

it('builds six month trend from aggregated transaction query', function () {
    $member = Member::factory()->create(['status' => 'active']);
    $cash = Account::factory()->for($member)->cash()->withBalance(0)->create();

    Transaction::factory()->for($cash)->create([
        'type' => 'credit',
        'amount' => 120,
        'transacted_at' => Carbon::now()->subMonths(1)->startOfMonth()->addDay(),
    ]);

    Transaction::factory()->for($cash)->create([
        'type' => 'debit',
        'amount' => 45,
        'transacted_at' => Carbon::now()->subMonths(1)->startOfMonth()->addDays(2),
    ]);

    Transaction::factory()->for($cash)->create([
        'type' => 'credit',
        'amount' => 70,
        'transacted_at' => Carbon::now()->subMonths(7),
    ]);

    $snapshot = app(MemberAccountsInsightsService::class)->snapshot();
    $trend = collect($snapshot['trend']);
    $lastMonthLabel = Carbon::now()->subMonths(1)->translatedFormat('M');
    $lastMonth = $trend->firstWhere('label', $lastMonthLabel);

    expect($trend)->toHaveCount(6)
        ->and($lastMonth)->not->toBeNull()
        ->and((float) $lastMonth['credits'])->toBe(120.0)
        ->and((float) $lastMonth['debits'])->toBe(45.0)
        ->and((float) $lastMonth['total'])->toBe(165.0);
});
