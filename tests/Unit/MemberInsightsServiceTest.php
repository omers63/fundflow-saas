<?php

use App\Models\Tenant\Member;
use App\Services\MemberInsightsService;
use App\Support\BusinessDay;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    Member::query()->delete();
});

test('insights snapshot aggregates member roster metrics', function () {
    Member::factory()->create([
        'name' => 'Active One',
        'status' => 'active',
        'monthly_contribution_amount' => 1000,
        'joined_at' => BusinessDay::now(),
    ]);

    Member::factory()->create([
        'name' => 'Delinquent One',
        'status' => 'active',
        'monthly_contribution_amount' => 2000,
    ]);

    $parent = Member::factory()->create(['name' => 'Parent', 'status' => 'active']);

    Member::factory()->create([
        'name' => 'Dependent',
        'status' => 'active',
        'parent_member_id' => $parent->id,
    ]);

    $snapshot = app(MemberInsightsService::class)->snapshot();

    expect($snapshot['total'])->toBe(4)
        ->and($snapshot['active'])->toBe(4)
        ->and($snapshot['delinquent'])->toBe(0)
        ->and($snapshot['dependents'])->toBe(1)
        ->and($snapshot['new_this_month'])->toBe(1)
        ->and($snapshot['status_breakdown'])->toHaveCount(3)
        ->and($snapshot['trend'])->toHaveCount(6)
        ->and($snapshot['sparkline'])->toHaveCount(8);
});

test('insights attention queue excludes withdrawn members', function () {
    Member::factory()->create([
        'name' => 'Withdrawn Member',
        'status' => 'withdrawn',
        'monthly_contribution_amount' => 500,
    ]);

    Member::factory()->create([
        'name' => 'Inactive Member',
        'status' => 'inactive',
        'monthly_contribution_amount' => 750,
    ]);

    $snapshot = app(MemberInsightsService::class)->snapshot();
    $queueNames = collect($snapshot['attention_queue'])->pluck('name')->all();

    expect($queueNames)->toContain('Inactive Member')
        ->and($queueNames)->not->toContain('Withdrawn Member');
});
