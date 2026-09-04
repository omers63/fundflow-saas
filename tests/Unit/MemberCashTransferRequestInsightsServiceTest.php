<?php

declare(strict_types=1);

use App\Models\Tenant\Member;
use App\Models\Tenant\MemberCashTransferRequest;
use App\Services\MemberCashTransferRequestInsightsService;
use App\Support\TenantRuntimeCache;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    MemberCashTransferRequest::query()->delete();
    Member::query()->delete();
    TenantRuntimeCache::forget('member_cash_transfer_request_insights.v1');
});

test('cash transfer insights snapshot aggregates pending accepted and amounts', function () {
    $from = Member::factory()->create();
    $to = Member::factory()->create();

    MemberCashTransferRequest::create([
        'from_member_id' => $from->id,
        'to_member_id' => $to->id,
        'recipient_name' => $to->name,
        'amount' => 300,
        'status' => 'pending',
    ]);
    MemberCashTransferRequest::create([
        'from_member_id' => $from->id,
        'to_member_id' => $to->id,
        'recipient_name' => $to->name,
        'amount' => 700,
        'status' => 'accepted',
    ]);
    MemberCashTransferRequest::create([
        'from_member_id' => $from->id,
        'to_member_id' => $to->id,
        'recipient_name' => $to->name,
        'amount' => 50,
        'status' => 'rejected',
    ]);

    $snapshot = app(MemberCashTransferRequestInsightsService::class)->snapshot();

    expect($snapshot['pending'])->toBe(1)
        ->and($snapshot['accepted'])->toBe(1)
        ->and($snapshot['rejected'])->toBe(1)
        ->and($snapshot['total'])->toBe(3)
        ->and($snapshot['pending_amount'])->toBe(300.0)
        ->and($snapshot['accepted_amount'])->toBe(700.0)
        ->and($snapshot['pending_url'])->toContain('cash-transfer')
        ->and($snapshot['hero']['tone'])->toBe('amber');
});

test('cash transfer insights hero is success when queue is clear', function () {
    $snapshot = app(MemberCashTransferRequestInsightsService::class)->snapshot();

    expect($snapshot['pending'])->toBe(0)
        ->and($snapshot['hero']['tone'])->toBe('success');
});
