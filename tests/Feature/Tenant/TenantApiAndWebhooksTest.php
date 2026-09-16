<?php

declare(strict_types=1);

use App\Jobs\DeliverWebhookJob;
use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Models\Tenant\WebhookDelivery;
use App\Models\Tenant\WebhookEndpoint;
use App\Services\Webhooks\WebhookDispatcher;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    $tenant = tenant();
    if ($tenant !== null && ! $tenant->domains()->where('domain', 'testing.localhost')->exists()) {
        $tenant->domains()->create(['domain' => 'testing.localhost']);
    }

    $this->tenantBaseUrl = 'http://testing.localhost';

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    WebhookDelivery::query()->delete();
    WebhookEndpoint::query()->delete();

    $this->admin = User::create([
        'name' => 'API Admin',
        'email' => 'api-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->member = Member::create([
        'user_id' => User::create([
            'name' => 'API Member',
            'email' => 'api-member@test.com',
            'password' => bcrypt('password'),
            'is_admin' => false,
        ])->id,
        'member_number' => 'MEM-API1',
        'name' => 'API Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
        'email' => 'api-member@test.com',
    ]);

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create([
        'type' => 'cash',
        'name' => 'Member Cash',
        'balance' => 100,
        'is_master' => false,
        'member_id' => $this->member->id,
    ]);
    Account::create([
        'type' => 'fund',
        'name' => 'Member Fund',
        'balance' => 2500,
        'is_master' => false,
        'member_id' => $this->member->id,
    ]);
});

test('api rejects missing bearer token', function () {
    $this->getJson($this->tenantBaseUrl.'/api/v1/members')
        ->assertUnauthorized();
});

test('api token lists members within tenant and respects ability', function () {
    $full = $this->admin->issueApiToken('full', ['*']);
    $loansOnly = $this->admin->issueApiToken('loans', ['loans:read']);

    $this->withToken($full['plain_text'])
        ->getJson($this->tenantBaseUrl.'/api/v1/members')
        ->assertSuccessful()
        ->assertJsonPath('data.0.member_number', 'MEM-API1');

    $this->withToken($loansOnly['plain_text'])
        ->getJson($this->tenantBaseUrl.'/api/v1/members')
        ->assertForbidden();

    $this->withToken($full['plain_text'])
        ->getJson($this->tenantBaseUrl.'/api/v1/balances/'.$this->member->id)
        ->assertSuccessful()
        ->assertJsonPath('data.fund_balance', 2500);
});

test('webhook delivery signs payload with hmac sha256', function () {
    Queue::fake();

    $endpoint = WebhookEndpoint::query()->create([
        'name' => 'Test hook',
        'url' => 'https://hooks.example.test/fundflow',
        'secret' => 'super-secret-hook',
        'events' => ['loan.approved'],
        'is_active' => true,
    ]);

    $dispatcher = app(WebhookDispatcher::class);
    expect($dispatcher->dispatch('loan.approved', ['loan_id' => 9]))->toBe(1);

    $delivery = WebhookDelivery::query()->first();
    expect($delivery)->not->toBeNull()
        ->and($delivery->status)->toBe(WebhookDelivery::STATUS_PENDING);

    $raw = json_encode($delivery->payload, JSON_THROW_ON_ERROR);
    expect(WebhookDispatcher::verifySignature($raw, $endpoint->secret, (string) $delivery->signature))->toBeTrue()
        ->and(WebhookDispatcher::verifySignature($raw, 'wrong', (string) $delivery->signature))->toBeFalse();

    Queue::assertPushed(DeliverWebhookJob::class);

    Http::fake([
        'hooks.example.test/*' => Http::response(['ok' => true], 200),
    ]);

    $dispatcher->deliver($delivery->fresh());

    expect($delivery->fresh()->status)->toBe(WebhookDelivery::STATUS_DELIVERED)
        ->and($delivery->fresh()->http_status)->toBe(200);

    Http::assertSent(function ($request) use ($endpoint): bool {
        $signature = $request->header('X-FundFlow-Signature')[0] ?? '';

        return $request->url() === $endpoint->url
            && WebhookDispatcher::verifySignature($request->body(), $endpoint->secret, $signature);
    });
});
