<?php

declare(strict_types=1);

use App\Filament\Member\Pages\MemberSettingsPage;
use App\Models\Central\Tenant;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    $tenant = Tenant::find('testing');
    $this->domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $this->domain)->exists()) {
        $tenant->domains()->create(['domain' => $this->domain]);
    }

    $member = Member::create([
        'member_number' => 'MEM-SET01',
        'name' => 'Settings Member',
        'email' => 'settings@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $this->memberUser = User::create([
        'name' => $member->name,
        'email' => $member->email,
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
        'preferred_locale' => 'en',
    ]);

    $member->update(['user_id' => $this->memberUser->id]);
    $this->member = $member->fresh();
});

test('settings page renders tabbed shell', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/settings')
        ->assertSuccessful()
        ->assertSee('ff-member-settings', false)
        ->assertSee(__('Profile'), false)
        ->assertSee(__('Contributions'), false)
        ->assertSee(__('Notifications'), false)
        ->assertSee(__('Payout details'), false);
});

test('legacy settings routes redirect to tabbed settings page', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/contribution-settings')
        ->assertRedirect('/member/settings?tab=contributions');

    $this->get('http://'.$this->domain.'/member/notification-preferences')
        ->assertRedirect('/member/settings?tab=notifications');
});

test('settings notifications tab can save preferences', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(MemberSettingsPage::class)
        ->set('activeTab', 'notifications')
        ->call('save')
        ->assertHasNoErrors();
});

test('settings page renders in arabic with faq-free profile labels', function () {
    Filament::setCurrentPanel('member');
    $this->memberUser->update(['preferred_locale' => 'ar']);
    app()->setLocale('ar');
    $this->actingAs($this->memberUser->fresh(), 'tenant');

    $this->get('http://'.$this->domain.'/member/settings?tab=profile')
        ->assertSuccessful()
        ->assertSee(__('Profile'), false)
        ->assertSee(__('Payout details'), false);
});
