<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\GatewayPayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Gateway\GatewayPaymentService;
use Illuminate\Support\Facades\Config;
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

    Config::set('gateway.driver', 'fake');
    Config::set('gateway.fake.webhook_secret', 'fake-gateway-secret');

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();
    GatewayPayment::query()->delete();
    BankTransaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 100000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->memberUser = User::create([
        'name' => 'Payer',
        'email' => 'gateway-payer@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-GW1',
        'name' => 'Gateway Payer',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
        'email' => 'gateway-payer@test.com',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->payments = app(GatewayPaymentService::class);
});

test('fake happy path posts member and master cash mirror and marks payment paid', function () {
    $payment = $this->payments->createIntent(
        $this->member,
        GatewayPayment::PURPOSE_DEPOSIT,
        250.0,
        expectedOwed: 250.0,
    );

    expect($payment->status)->toBe(GatewayPayment::STATUS_PENDING)
        ->and($payment->provider)->toBe(GatewayPayment::PROVIDER_FAKE)
        ->and($payment->provider_ref)->not->toBeEmpty();

    $paid = $this->payments->completeFake($payment);

    expect($paid->status)->toBe(GatewayPayment::STATUS_PAID)
        ->and($paid->posted_at)->not->toBeNull()
        ->and($paid->bank_transaction_id)->not->toBeNull();

    $credits = Transaction::query()
        ->where('reference_type', $paid->getMorphClass())
        ->where('reference_id', $paid->id)
        ->where('type', 'credit')
        ->get();

    expect($credits)->toHaveCount(2);
    expect($credits->sum(fn ($t) => (float) $t->amount))->toBe(500.0);

    $bankTxn = BankTransaction::query()->findOrFail($paid->bank_transaction_id);
    expect($bankTxn->is_cleared)->toBeTrue()
        ->and((float) $bankTxn->amount)->toBe(250.0);
});

test('duplicate webhook does not double post cash', function () {
    $payment = $this->payments->createIntent(
        $this->member,
        GatewayPayment::PURPOSE_EMI,
        100.0,
        expectedOwed: 100.0,
    );

    $this->payments->completeFake($payment);
    $memberCash = (float) $this->member->cashAccount->fresh()->balance;
    $masterCash = (float) Account::masterCash()->fresh()->balance;

    $response = $this->postJson(
        $this->tenantBaseUrl.route('tenant.webhooks.payments', ['provider' => 'fake'], false),
        [
            'provider_ref' => $payment->provider_ref,
            'status' => 'paid',
            'amount' => 100,
        ],
        [
            'X-Gateway-Signature' => 'fake-gateway-secret',
        ],
    );

    $response->assertSuccessful();

    expect((float) $this->member->cashAccount->fresh()->balance)->toBe($memberCash);
    expect((float) Account::masterCash()->fresh()->balance)->toBe($masterCash);
    expect(GatewayPayment::query()->where('status', GatewayPayment::STATUS_PAID)->count())->toBe(1);
});

test('webhook with invalid signature fails', function () {
    $payment = $this->payments->createIntent(
        $this->member,
        GatewayPayment::PURPOSE_FEE,
        50.0,
        expectedOwed: 50.0,
    );

    $response = $this->postJson(
        $this->tenantBaseUrl.route('tenant.webhooks.payments', ['provider' => 'fake'], false),
        [
            'provider_ref' => $payment->provider_ref,
            'status' => 'paid',
        ],
        [
            'X-Gateway-Signature' => 'wrong-secret',
        ],
    );

    $response->assertStatus(400);
    expect($payment->fresh()->status)->toBe(GatewayPayment::STATUS_PENDING);
});

test('create intent rejects amount that does not match owed', function () {
    $this->payments->createIntent(
        $this->member,
        GatewayPayment::PURPOSE_CONTRIBUTION,
        90.0,
        expectedOwed: 100.0,
    );
})->throws(InvalidArgumentException::class);
