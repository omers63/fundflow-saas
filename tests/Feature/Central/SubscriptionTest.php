<?php

use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use App\Models\Central\Tenant;

test('can create a subscription for a tenant', function () {
    $plan = Plan::factory()->create();
    $tenant = Tenant::factory()->createQuietly(['plan_id' => $plan->id]);

    $subscription = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'plan_id' => $plan->id,
        'status' => 'active',
    ]);

    expect($subscription)
        ->toBeInstanceOf(Subscription::class)
        ->status->toBe('active')
        ->tenant_id->toBe($tenant->id)
        ->plan_id->toBe($plan->id);

    $this->assertDatabaseHas('subscriptions', [
        'id' => $subscription->id,
        'tenant_id' => $tenant->id,
        'status' => 'active',
    ], 'mysql');
});

test('subscription status can be updated', function () {
    $tenant = Tenant::factory()->createQuietly();
    $subscription = Subscription::factory()->create([
        'tenant_id' => $tenant->id,
        'status' => 'active',
    ]);

    $subscription->update(['status' => 'expired']);

    expect($subscription->fresh()->status)->toBe('expired');
});
