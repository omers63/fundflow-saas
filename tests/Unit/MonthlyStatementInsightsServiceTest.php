<?php

use App\Models\Tenant\Member;
use App\Models\Tenant\MonthlyStatement;
use App\Services\MonthlyStatementInsightsService;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    MonthlyStatement::query()->delete();
    Member::query()->delete();
});

test('insights snapshot aggregates monthly statement delivery metrics', function () {
    $period = now()->subMonthNoOverflow()->format('Y-m');

    $member = Member::factory()->create(['status' => 'active']);

    MonthlyStatement::create([
        'member_id' => $member->id,
        'period' => $period,
        'opening_balance' => 0,
        'total_contributions' => 1000,
        'total_repayments' => 200,
        'closing_balance' => 800,
        'generated_at' => now(),
        'notified_at' => null,
    ]);

    MonthlyStatement::create([
        'member_id' => Member::factory()->create(['status' => 'active'])->id,
        'period' => $period,
        'opening_balance' => 0,
        'total_contributions' => 500,
        'total_repayments' => 0,
        'closing_balance' => 500,
        'generated_at' => now()->subDay(),
        'notified_at' => now(),
    ]);

    $snapshot = app(MonthlyStatementInsightsService::class)->snapshot();

    expect($snapshot['total'])->toBe(2)
        ->and($snapshot['pending_notify'])->toBe(1)
        ->and($snapshot['notified'])->toBe(1)
        ->and($snapshot['latest_period']['count'])->toBe(2)
        ->and($snapshot['trend'])->toHaveCount(6)
        ->and($snapshot['sparkline'])->toHaveCount(8)
        ->and($snapshot['unnotified_queue'])->not->toBeEmpty();
});

test('member snapshot returns portfolio metrics for a member', function () {
    $member = Member::factory()->create(['status' => 'active']);

    MonthlyStatement::create([
        'member_id' => $member->id,
        'period' => '2026-03',
        'opening_balance' => 0,
        'total_contributions' => 1000,
        'total_repayments' => 100,
        'closing_balance' => 900,
        'generated_at' => now()->subMonths(2),
        'notified_at' => now()->subMonths(2),
    ]);

    MonthlyStatement::create([
        'member_id' => $member->id,
        'period' => '2026-04',
        'opening_balance' => 900,
        'total_contributions' => 1000,
        'total_repayments' => 200,
        'closing_balance' => 1700,
        'generated_at' => now(),
        'notified_at' => null,
    ]);

    $snapshot = app(MonthlyStatementInsightsService::class)->memberSnapshot($member->id);

    expect($snapshot['total'])->toBe(2)
        ->and($snapshot['latest']['period'])->toBe('2026-04')
        ->and($snapshot['latest']['closing'])->toBe(1700.0)
        ->and($snapshot['trend'])->not->toBeEmpty();
});
