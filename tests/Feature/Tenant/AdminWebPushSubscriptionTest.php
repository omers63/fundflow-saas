<?php

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

    $this->admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-webpush@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Member',
        'email' => 'member-webpush@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);
});

test('admin can store a web push subscription', function () {
    $payload = [
        'endpoint' => 'https://push.example.test/subscription/1',
        'keys' => [
            'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Tu1iMBz635-6P4Qm6NjHNHmnc',
            'auth' => 'tBHItJI5svbpez7KI4CCXg',
        ],
    ];

    $response = $this->actingAs($this->admin, 'tenant')
        ->postJson($this->tenantBaseUrl.route('tenant.admin.webpush.subscribe.store', [], false), $payload);

    $response->assertOk()
        ->assertJson(['status' => 'subscribed']);

    $this->assertDatabaseHas('push_subscriptions', [
        'subscribable_type' => User::class,
        'subscribable_id' => $this->admin->id,
        'endpoint' => $payload['endpoint'],
        'public_key' => $payload['keys']['p256dh'],
        'auth_token' => $payload['keys']['auth'],
    ], 'tenant');
});

test('admin can delete a web push subscription', function () {
    $endpoint = 'https://push.example.test/subscription/2';

    $this->admin->updatePushSubscription(
        $endpoint,
        'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Tu1iMBz635-6P4Qm6NjHNHmnc',
        'tBHItJI5svbpez7KI4CCXg',
    );

    $response = $this->actingAs($this->admin, 'tenant')
        ->deleteJson($this->tenantBaseUrl.route('tenant.admin.webpush.subscribe.destroy', [], false), [
            'endpoint' => $endpoint,
        ]);

    $response->assertOk()
        ->assertJson(['status' => 'unsubscribed']);

    $this->assertDatabaseMissing('push_subscriptions', [
        'endpoint' => $endpoint,
    ], 'tenant');
});

test('non-admin cannot manage web push subscriptions', function () {
    $payload = [
        'endpoint' => 'https://push.example.test/subscription/3',
        'keys' => [
            'p256dh' => 'BNcRdreALRFXTkOOUHK1EtK2wtaz5Ry4YfYCA_0QTpQtUbVlUls0VJXg7A8u-Tu1iMBz635-6P4Qm6NjHNHmnc',
            'auth' => 'tBHItJI5svbpez7KI4CCXg',
        ],
    ];

    $this->actingAs($this->memberUser, 'tenant')
        ->postJson($this->tenantBaseUrl.route('tenant.admin.webpush.subscribe.store', [], false), $payload)
        ->assertForbidden();
});
