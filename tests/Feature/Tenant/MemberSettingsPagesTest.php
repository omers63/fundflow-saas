<?php

use App\Filament\Member\Pages\MemberSettingsPage;
use App\Filament\Member\Pages\MyContributionSettingsPage;
use App\Filament\Member\Pages\MyNotificationPreferencesPage;
use App\Filament\Member\Pages\SupportPage;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberCommunicationPreference;
use App\Models\Tenant\SupportRequest;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Tenant\NotificationPreferenceService;
use App\Support\BusinessDaySettings;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Member::query()->delete();
    User::query()->delete();

    $this->memberUser = User::create([
        'name' => 'Settings Member',
        'email' => 'settings@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-S001',
        'name' => 'Settings Member',
        'email' => 'settings@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);

    $this->actingAs($this->memberUser, 'tenant');
    Filament::setCurrentPanel('member');
});

afterEach(function () {
    Carbon::setTestNow();
    BusinessDaySettings::saveFromForm(null);
});

test('member can view contribution settings page', function () {
    Livewire::test(MyContributionSettingsPage::class)
        ->assertSuccessful()
        ->assertSee(__('Monthly Contribution'), false)
        ->assertSee('1,000');
});

test('member contribution settings shows request larger cycle amount when unpaid for open cycle', function () {
    app()->setLocale('en');

    Livewire::test(MyContributionSettingsPage::class)
        ->assertSuccessful()
        ->assertActionVisible('requestOpenCycleAmount')
        ->assertSee(__('Request larger cycle amount'), false)
        ->assertSee(__('Larger amount for this cycle only?'), false);
});

test('member settings contributions tab exposes request larger cycle amount action', function () {
    app()->setLocale('en');

    Livewire::test(MemberSettingsPage::class)
        ->set('activeTab', 'contributions')
        ->assertSuccessful()
        ->assertActionVisible('requestOpenCycleAmount')
        ->assertSee(__('Request larger cycle amount'), false);
});

test('member can update monthly contribution amount when there are no arrears', function () {
    Carbon::setTestNow(Carbon::parse('2026-06-05'));
    BusinessDaySettings::saveFromForm('2026-06-05');

    $this->member->update([
        'joined_at' => Carbon::parse('2026-06-01'),
        'contribution_arrears_cutoff_date' => Carbon::parse('2026-06-01'),
    ]);

    Livewire::test(MyContributionSettingsPage::class)
        ->assertSet('allocationChangeBlocked', false)
        ->mountAction('save_allocation')
        ->setActionData(['monthly_contribution_amount' => 1500])
        ->callMountedAction()
        ->assertNotified();

    expect((int) $this->member->fresh()->monthly_contribution_amount)->toBe(1500);
});

test('member can update allocation when only the open cycle contribution is unpaid', function () {
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

    Livewire::test(MyContributionSettingsPage::class)
        ->assertSet('allocationChangeBlocked', false)
        ->mountAction('save_allocation')
        ->setActionData(['monthly_contribution_amount' => 1500])
        ->callMountedAction()
        ->assertNotified();

    expect((int) $this->member->fresh()->monthly_contribution_amount)->toBe(1500);
});

test('member can save notification preferences including push', function () {
    config([
        'webpush.vapid.public_key' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U',
        'webpush.vapid.private_key' => 'UUxI4O8-FbRqjAihg6f42nd_pmTQj2vmanuelys70Ho',
    ]);

    Livewire::test(MyNotificationPreferencesPage::class)
        ->assertSuccessful()
        ->assertSee(__('Push'))
        ->call('toggleChannel', NotificationPreferenceService::CONTRIBUTIONS, NotificationPreferenceService::CH_SMS)
        ->call('toggleChannel', NotificationPreferenceService::CONTRIBUTIONS, NotificationPreferenceService::CH_PUSH)
        ->call('save')
        ->assertNotified();

    $channels = MemberCommunicationPreference::channelsFor(
        $this->memberUser->id,
        NotificationPreferenceService::CONTRIBUTIONS,
        [],
    );

    expect($channels)->toContain(NotificationPreferenceService::CH_SMS)
        ->and($channels)->not->toContain(NotificationPreferenceService::CH_PUSH)
        ->and($channels)->toContain(NotificationPreferenceService::CH_IN_APP);
});

test('notification preference categories render in arabic', function () {
    app()->setLocale('ar');

    Livewire::test(MyNotificationPreferencesPage::class)
        ->assertSuccessful()
        ->assertSee(__('Contributions'), false)
        ->assertSee(__('Loan repayments'), false);
});

test('member can submit support request', function () {
    Livewire::test(SupportPage::class)
        ->assertSuccessful()
        ->mountAction('submit_request')
        ->setActionData([
            'category' => SupportRequest::CATEGORY_GENERAL_INQUIRY,
            'subject' => 'Need help',
            'message' => 'Please review my account balance.',
        ])
        ->callMountedAction()
        ->assertNotified();

    expect(SupportRequest::query()->count())->toBe(1);

    $request = SupportRequest::query()->first();
    expect($request->user_id)->toBe($this->memberUser->id)
        ->and($request->member_id)->toBe($this->member->id)
        ->and($request->subject)->toBe('Need help');
});
