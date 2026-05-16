<?php

use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Services\MembershipApplicationInsightsService;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    MembershipApplication::query()->delete();
    Member::query()->delete();
});

test('insights snapshot aggregates application pipeline metrics', function () {
    MembershipApplication::create([
        'name' => 'Pending One',
        'email' => 'pending-one@test.com',
        'application_type' => 'new',
        'status' => 'pending',
        'membership_fee_amount' => 50,
    ]);

    $pendingOverSla = MembershipApplication::create([
        'name' => 'Pending Two',
        'email' => 'pending-two@test.com',
        'application_type' => 'renew',
        'status' => 'pending',
    ]);
    $pendingOverSla->forceFill(['created_at' => now()->subDays(10)])->save();

    $approved = MembershipApplication::create([
        'name' => 'Approved',
        'email' => 'approved@test.com',
        'application_type' => 'new',
        'status' => 'approved',
        'reviewed_at' => now()->subDay(),
    ]);
    $approved->forceFill(['created_at' => now()->subDays(3)])->save();

    $rejected = MembershipApplication::create([
        'name' => 'Rejected',
        'email' => 'rejected@test.com',
        'application_type' => 'new',
        'status' => 'rejected',
        'reviewed_at' => now(),
    ]);
    $rejected->forceFill(['created_at' => now()->subDays(2)])->save();

    $snapshot = app(MembershipApplicationInsightsService::class)->snapshot();

    expect($snapshot['pending'])->toBe(2)
        ->and($snapshot['approved'])->toBe(1)
        ->and($snapshot['rejected'])->toBe(1)
        ->and($snapshot['total'])->toBe(4)
        ->and($snapshot['pending_over_sla'])->toBe(1)
        ->and($snapshot['approval_rate'])->toBe(50.0)
        ->and($snapshot['fees']['pending_total'])->toBe(50.0)
        ->and($snapshot['fees']['pending_with_fee'])->toBe(1)
        ->and($snapshot['trend'])->toHaveCount(6)
        ->and($snapshot['sparkline'])->toHaveCount(8)
        ->and($snapshot['type_breakdown'])->not->toBeEmpty()
        ->and($snapshot['oldest_pending'])->not->toBeEmpty();
});
