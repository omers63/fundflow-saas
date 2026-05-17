<?php

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Services\ContributionCycleService;
use App\Services\ContributionInsightsService;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    Contribution::query()->delete();
    Member::query()->delete();
});

test('insights snapshot aggregates contribution pipeline metrics', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
    ]);

    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();
    $period = Contribution::periodDate($month, $year);

    Contribution::factory()->for($member)->create([
        'period' => $period,
        'amount' => 1000,
        'status' => 'pending',
        'is_late' => true,
    ]);

    Contribution::factory()->for($member)->posted()->create([
        'period' => now()->subMonths(3)->startOfMonth()->toDateString(),
        'amount' => 1000,
    ]);

    Contribution::factory()->for($member)->failed()->create([
        'period' => now()->subMonths(2)->startOfMonth()->toDateString(),
        'amount' => 500,
    ]);

    $snapshot = app(ContributionInsightsService::class)->snapshot();

    expect($snapshot['pending'])->toBe(1)
        ->and($snapshot['posted'])->toBe(1)
        ->and($snapshot['failed'])->toBe(1)
        ->and($snapshot['late_count'])->toBe(1)
        ->and($snapshot['open_period']['label'])->not->toBeEmpty()
        ->and($snapshot['method_breakdown'])->not->toBeEmpty()
        ->and($snapshot['trend'])->toHaveCount(6)
        ->and($snapshot['sparkline'])->toHaveCount(8)
        ->and($snapshot['oldest_pending'])->not->toBeEmpty();
});
