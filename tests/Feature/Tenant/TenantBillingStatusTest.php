<?php

declare(strict_types=1);

use App\Models\Central\Plan;
use App\Models\Central\Subscription;
use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Support\Billing\TenantBillingStatus;
use App\Support\Billing\TenantFeatureGate;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->tenant = $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    $this->plan = Plan::query()->firstOrCreate(
        ['slug' => 'test-billing'],
        [
            'name' => 'Test Billing',
            'price' => 10,
            'currency' => 'SAR',
            'billing_cycle' => 'monthly',
            'duration_months' => 1,
            'description' => 'Test',
            'data' => [
                'max_members' => 10,
                'features' => ['gateway', 'api'],
            ],
            'is_active' => true,
        ],
    );

    $this->tenant->update(['plan_id' => $this->plan->id]);

    Subscription::query()->where('tenant_id', $this->tenant->id)->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 1000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 1000, 'is_master' => true]);

    $this->member = Member::create([
        'user_id' => User::create([
            'name' => 'Bill',
            'email' => 'bill@test.com',
            'password' => bcrypt('password'),
            'is_admin' => false,
        ])->id,
        'member_number' => 'MEM-BILL1',
        'name' => 'Bill',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
        'email' => 'bill@test.com',
    ]);

    $this->memberCash = Account::create([
        'type' => 'cash',
        'name' => 'Member Cash',
        'balance' => 500,
        'is_master' => false,
        'member_id' => $this->member->id,
    ]);
});

test('feature gate reads plan features and defaults when unset', function () {
    $gate = app(TenantFeatureGate::class);

    expect($gate->allows(TenantFeatureGate::FEATURE_API))->toBeTrue()
        ->and($gate->allows(TenantFeatureGate::FEATURE_OCR))->toBeFalse()
        ->and($gate->maxMembers())->toBe(10)
        ->and($gate->activeMemberCount())->toBe(1);
});

test('billing grace then read-only blocks money mutations', function () {
    Subscription::query()->create([
        'tenant_id' => $this->tenant->id,
        'plan_id' => $this->plan->id,
        'status' => 'expired',
        'starts_at' => now()->subMonths(2),
        'ends_at' => now()->subDays(3),
    ]);

    $status = app(TenantBillingStatus::class);
    expect($status->mode())->toBe(TenantBillingStatus::MODE_GRACE)
        ->and($status->canMutateMoney())->toBeTrue();

    Subscription::query()->where('tenant_id', $this->tenant->id)->update([
        'ends_at' => now()->subDays(TenantBillingStatus::GRACE_DAYS + 2),
    ]);

    $this->tenant->unsetRelation('subscription');
    expect(app(TenantBillingStatus::class)->mode())->toBe(TenantBillingStatus::MODE_READ_ONLY);

    expect(fn () => app(AccountingService::class)->creditMemberCashWithMasterMirror(
        $this->memberCash,
        10,
        'Should fail',
        '(mirror)',
        null,
        null,
        $this->member->id,
    ))->toThrow(InvalidArgumentException::class);
});
