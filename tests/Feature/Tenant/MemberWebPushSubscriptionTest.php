<?php

use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use NotificationChannels\WebPush\PushSubscription;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    $tenant = tenant();
    $domain = $tenant->domains()->first()?->domain ?? 'testing.localhost';

    if ($tenant !== null && ! $tenant->domains()->where('domain', 'testing.localhost')->exists()) {
        $tenant->domains()->create(['domain' => 'testing.localhost']);
        $domain = 'testing.localhost';
    }

    $this->tenantBaseUrl = 'http://'.$domain;

    PushSubscription::query()->delete();
    User::query()->delete();
    Member::query()->delete();

    $this->memberUser = User::create([
        'name' => 'Member',
        'email' => 'member-webpush-portal@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-PUSH',
        'name' => 'Member Push',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $this->admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-only-webpush@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);
});

test('member can store a web push subscription', function () {
    $payload = [
        'endpoint' => 'https://push.example.test/member-subscription/1',
        'keys' => [
            'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Tu1iMBz635-6P4Qm6NjHNHmnc',
            'auth' => 'tBHItJI5svbpez7KI4CCXg',
        ],
    ];

    $response = $this->actingAs($this->memberUser, 'tenant')
        ->postJson($this->tenantBaseUrl.route('tenant.member.webpush.subscribe.store', [], false), $payload);

    $response->assertOk()
        ->assertJson(['status' => 'subscribed']);

    $this->assertDatabaseHas('push_subscriptions', [
        'subscribable_type' => User::class,
        'subscribable_id' => $this->memberUser->id,
        'endpoint' => $payload['endpoint'],
    ], 'tenant');
});

test('admin without member profile cannot store member web push subscription', function () {
    $payload = [
        'endpoint' => 'https://push.example.test/member-subscription/2',
        'keys' => [
            'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Tu1iMBz635-6P4Qm6NjHNHmnc',
            'auth' => 'tBHItJI5svbpez7KI4CCXg',
        ],
    ];

    $this->actingAs($this->admin, 'tenant')
        ->postJson($this->tenantBaseUrl.route('tenant.member.webpush.subscribe.store', [], false), $payload)
        ->assertForbidden();
});
