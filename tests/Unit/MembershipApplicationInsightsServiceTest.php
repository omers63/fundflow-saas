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
        'membership_fee_amount' => 25,
        'membership_fee_required_amount' => 50,
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

    MembershipApplication::create([
        'name' => 'Approved Legacy Arrears',
        'email' => 'approved-legacy@test.com',
        'application_type' => 'renew',
        'status' => 'approved',
        'rejection_reason' => 'Subscription fee arrears: 15.00',
        'reviewed_at' => now()->subDays(2),
    ]);

    $snapshot = app(MembershipApplicationInsightsService::class)->snapshot();

    expect($snapshot['pending'])->toBe(2)
        ->and($snapshot['approved'])->toBe(2)
        ->and($snapshot['rejected'])->toBe(1)
        ->and($snapshot['total'])->toBe(5)
        ->and($snapshot['pending_over_sla'])->toBe(1)
        ->and($snapshot['approval_rate'])->toBe(66.7)
        ->and($snapshot['fees']['pending_total'])->toBe(50.0)
        ->and($snapshot['fees']['pending_with_fee'])->toBe(1)
        ->and($snapshot['fees']['subscription_arrears_count'])->toBe(2)
        ->and($snapshot['fees']['subscription_arrears_total'])->toBe(40.0)
        ->and($snapshot['trend'])->toHaveCount(6)
        ->and($snapshot['sparkline'])->toHaveCount(8)
        ->and($snapshot['type_breakdown'])->not->toBeEmpty()
        ->and($snapshot['oldest_pending'])->not->toBeEmpty();
});
