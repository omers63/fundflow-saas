<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\Tenant\ImpersonationService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->delete();

    $tenant = Tenant::find('testing');
    $this->domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }
});

test('business day override renders flashing footer banner on tenant panel', function () {
    Setting::set('general', 'business_day', '2026-03-15');

    $admin = User::create([
        'name' => 'Banner Admin',
        'email' => 'banner-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');

    $this->get('http://'.$this->domain.'/admin')
        ->assertSuccessful()
        ->assertSee('ff-status-footer-banner--business-day', false);
});

test('business day banner can be disabled on admin portal while override stays active', function () {
    Setting::set('general', 'business_day', '2026-03-15');
    Setting::set('general', 'business_day_banner_admin', '0');
    Setting::set('general', 'business_day_banner_member', '1');

    $admin = User::create([
        'name' => 'Banner Admin Off',
        'email' => 'banner-admin-off@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');

    $this->get('http://' . $this->domain . '/admin')
        ->assertSuccessful()
        ->assertDontSee('ff-status-footer-banner--business-day', false);
});

test('business day banner can be disabled on member portal while override stays active', function () {
    Setting::set('general', 'business_day', '2026-03-15');
    Setting::set('general', 'business_day_banner_admin', '1');
    Setting::set('general', 'business_day_banner_member', '0');

    $member = Member::create([
        'member_number' => 'MEM-BANNER' . uniqid(),
        'name' => 'Banner Member',
        'email' => 'banner-member-off@fund.test',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(12),
        'status' => 'active',
    ]);

    $user = User::create([
        'name' => $member->name,
        'email' => $member->email,
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $member->update(['user_id' => $user->id]);

    $this->actingAs($user, 'tenant');

    $this->get('http://' . $this->domain . '/member')
        ->assertSuccessful()
        ->assertDontSee('ff-status-footer-banner--business-day', false);
});

test('impersonation renders footer banner with return action', function () {
    $parent = Member::create([
        'member_number' => 'MEM-P'.uniqid(),
        'name' => 'Parent Member',
        'email' => 'parent-banner@fund.test',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(12),
        'status' => 'active',
    ]);

    $dependent = Member::create([
        'member_number' => 'MEM-D'.uniqid(),
        'name' => 'Dependent Member',
        'email' => 'dependent-banner@fund.test',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(6),
        'status' => 'active',
        'parent_member_id' => $parent->id,
    ]);

    $parentUser = User::create([
        'name' => $parent->name,
        'email' => $parent->email,
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $parent->update(['user_id' => $parentUser->id]);

    $dependentUser = User::create([
        'name' => $dependent->name,
        'email' => $dependent->email,
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $dependent->update(['user_id' => $dependentUser->id]);

    app(ImpersonationService::class)->start($parentUser, $dependentUser, $dependent);

    $this->get('http://'.$this->domain.'/member')
        ->assertSuccessful()
        ->assertSee('ff-status-footer-banner--impersonation', false)
        ->assertSee(__('Return to parent portal'), false);
});
