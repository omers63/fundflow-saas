<?php

use App\Filament\Member\Pages\MyContributionSettingsPage;
use App\Filament\Member\Pages\MyNotificationPreferencesPage;
use App\Filament\Member\Pages\SupportPage;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberCommunicationPreference;
use App\Models\Tenant\SupportRequest;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Tenant\NotificationPreferenceService;
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

test('member can view contribution settings page', function () {
    Livewire::test(MyContributionSettingsPage::class)
        ->assertSuccessful()
        ->assertSee('Monthly Contribution')
        ->assertSee('1,000');
});

test('member can update monthly contribution amount when there are no arrears', function () {
    Livewire::test(MyContributionSettingsPage::class)
        ->mountAction('save_allocation')
        ->setActionData(['monthly_contribution_amount' => 1500])
        ->callMountedAction()
        ->assertNotified();

    expect((int) $this->member->fresh()->monthly_contribution_amount)->toBe(1500);
});

test('member can save notification preferences', function () {
    Livewire::test(MyNotificationPreferencesPage::class)
        ->assertSuccessful()
        ->call('toggleChannel', NotificationPreferenceService::CONTRIBUTIONS, NotificationPreferenceService::CH_SMS)
        ->call('save')
        ->assertNotified();

    $channels = MemberCommunicationPreference::channelsFor(
        $this->memberUser->id,
        NotificationPreferenceService::CONTRIBUTIONS,
        [],
    );

    expect($channels)->toContain(NotificationPreferenceService::CH_SMS);
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
