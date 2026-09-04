<?php

declare(strict_types=1);

use App\Models\Tenant\FundOutRequest;
use App\Models\Tenant\Member;
use App\Services\FundOutRequestInsightsService;
use App\Support\TenantRuntimeCache;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    FundOutRequest::query()->delete();
    Member::query()->delete();
    TenantRuntimeCache::forget('fund_out_request_insights.v1');
});

test('fund out insights snapshot aggregates pending accepted and amounts', function () {
    $member = Member::factory()->create();

    FundOutRequest::create([
        'member_id' => $member->id,
        'amount' => 1500,
        'status' => 'pending',
    ]);
    FundOutRequest::create([
        'member_id' => $member->id,
        'amount' => 500,
        'status' => 'pending',
    ]);
    FundOutRequest::create([
        'member_id' => $member->id,
        'amount' => 2000,
        'status' => 'accepted',
    ]);
    FundOutRequest::create([
        'member_id' => $member->id,
        'amount' => 100,
        'status' => 'rejected',
    ]);

    $snapshot = app(FundOutRequestInsightsService::class)->snapshot();

    expect($snapshot['pending'])->toBe(2)
        ->and($snapshot['accepted'])->toBe(1)
        ->and($snapshot['rejected'])->toBe(1)
        ->and($snapshot['total'])->toBe(4)
        ->and($snapshot['pending_amount'])->toBe(2000.0)
        ->and($snapshot['accepted_amount'])->toBe(2000.0)
        ->and($snapshot['pending_url'])->toContain('fund-out')
        ->and($snapshot['hero']['tone'])->toBe('amber');
});

test('fund out insights hero is success when queue is clear', function () {
    $snapshot = app(FundOutRequestInsightsService::class)->snapshot();

    expect($snapshot['pending'])->toBe(0)
        ->and($snapshot['hero']['tone'])->toBe('success');
});
