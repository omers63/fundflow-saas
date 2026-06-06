<?php

declare(strict_types=1);

use App\Filament\Member\Pages\BusinessDayTestingPage;
use App\Models\Central\Tenant;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Tenant\ImpersonationService;
use App\Support\BusinessDaySettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
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

test('member can save and clear business day from testing page', function () {
    $member = Member::create([
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Business Day Tester',
        'email' => 'biz-day-member@fund.test',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(6),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $user = User::create([
        'name' => $member->name,
        'email' => $member->email,
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $member->update(['user_id' => $user->id]);

    Filament::setCurrentPanel('member');
    $this->actingAs($user, 'tenant');

    Livewire::test(BusinessDayTestingPage::class)
        ->fillForm(['business_day' => '2026-04-10'])
        ->call('saveBusinessDay')
        ->assertNotified(__('Business day updated'));

    expect(BusinessDaySettings::forForm())->toBe('2026-04-10');

    Livewire::test(BusinessDayTestingPage::class)
        ->call('clearBusinessDay')
        ->assertNotified(__('Business day override cleared'));

    expect(BusinessDaySettings::forForm())->toBeNull();
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
