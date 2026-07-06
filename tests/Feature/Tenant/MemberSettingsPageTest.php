<?php

declare(strict_types=1);

use App\Filament\Member\Pages\MemberSettingsPage;
use App\Models\Central\Tenant;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Support\BusinessDaySettings;
use Carbon\Carbon;
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

afterEach(function () {
    Carbon::setTestNow();
    BusinessDaySettings::saveFromForm(null);
});

test('settings page renders tabbed shell', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/settings')
        ->assertSuccessful()
        ->assertSee('ff-member-settings', false)
        ->assertSee(__('Profile'), false)
        ->assertSee(__('Account'), false)
        ->assertSee(__('Contributions'), false)
        ->assertSee(__('Notifications'), false)
        ->assertDontSee(__('Payout details'), false);
});

test('legacy settings routes redirect to tabbed settings page', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/contribution-settings')
        ->assertRedirect('/member/settings?tab=contributions');

    $this->get('http://'.$this->domain.'/member/notification-preferences')
        ->assertRedirect('/member/settings?tab=notifications');

    $this->get('http://'.$this->domain.'/member/edit-profile')
        ->assertRedirect('/member/settings?tab=profile');
});

test('settings notifications tab can save preferences', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(MemberSettingsPage::class)
        ->set('activeTab', 'notifications')
        ->call('save')
        ->assertHasNoErrors();
});

test('settings contributions tab allows allocation when prior cycles are clear', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));
    BusinessDaySettings::saveFromForm('2026-06-15');

    $this->member->update([
        'joined_at' => Carbon::parse('2024-06-01'),
        'contribution_arrears_cutoff_date' => Carbon::parse('2024-06-01'),
    ]);

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $cursor = Carbon::parse('2024-06-01')->startOfMonth();
    $openStart = Carbon::create($openYear, $openMonth, 1)->startOfMonth();

    while ($cursor->lt($openStart)) {
        Contribution::create([
            'member_id' => $this->member->id,
            'period' => Contribution::periodDate((int) $cursor->month, (int) $cursor->year),
            'amount' => 1000,
            'status' => 'posted',
            'posted_at' => $cursor->copy(),
        ]);
        $cursor->addMonthNoOverflow();
    }

    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/settings?tab=contributions')
        ->assertSuccessful()
        ->assertDontSee(__('Allocation locked'), false);

    Carbon::setTestNow();
    BusinessDaySettings::saveFromForm(null);
});

test('settings contributions tab save allocation when prior cycles are clear', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-15'));
    BusinessDaySettings::saveFromForm('2026-06-15');

    $this->member->update([
        'joined_at' => Carbon::parse('2024-06-01'),
        'contribution_arrears_cutoff_date' => Carbon::parse('2024-06-01'),
    ]);

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $cursor = Carbon::parse('2024-06-01')->startOfMonth();
    $openStart = Carbon::create($openYear, $openMonth, 1)->startOfMonth();

    while ($cursor->lt($openStart)) {
        Contribution::create([
            'member_id' => $this->member->id,
            'period' => Contribution::periodDate((int) $cursor->month, (int) $cursor->year),
            'amount' => 1000,
            'status' => 'posted',
            'posted_at' => $cursor->copy(),
        ]);
        $cursor->addMonthNoOverflow();
    }

    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(MemberSettingsPage::class)
        ->set('activeTab', 'contributions')
        ->assertSet('allocationChangeBlocked', false)
        ->mountAction('save_allocation')
        ->setActionData(['monthly_contribution_amount' => 1500])
        ->callMountedAction()
        ->assertNotified();

    expect((int) $this->member->fresh()->monthly_contribution_amount)->toBe(1500);
});

test('settings tab switch keeps all tab panels mounted', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(MemberSettingsPage::class)
        ->assertSet('activeTab', 'profile')
        ->call('setTab', 'contributions')
        ->assertSet('activeTab', 'contributions')
        ->call('setTab', 'notifications')
        ->assertSet('activeTab', 'notifications')
        ->call('setTab', 'profile')
        ->assertSet('activeTab', 'profile')
        ->assertHasNoErrors();
});

test('settings page renders in arabic with faq-free profile labels', function () {
    Filament::setCurrentPanel('member');
    $this->memberUser->update(['preferred_locale' => 'ar']);
    app()->setLocale('ar');
    $this->actingAs($this->memberUser->fresh(), 'tenant');

    $this->get('http://'.$this->domain.'/member/settings?tab=profile')
        ->assertSuccessful()
        ->assertSee(__('Account'), false)
        ->assertSee(__('Payout bank details'), false);
});
