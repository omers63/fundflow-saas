<?php

declare(strict_types=1);

use App\Filament\Member\Pages\CommunicationsPage;
use App\Models\Central\Tenant;
use App\Models\Tenant\Member;
use App\Models\Tenant\NotificationLog;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Support\MemberFaq;
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
        'member_number' => 'MEM-HELP01',
        'name' => 'Help Member',
        'email' => 'help@fund.test',
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

test('help page renders communications tabs including faq and alerts', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/help')
        ->assertSuccessful()
        ->assertSee('ff-member-communications', false)
        ->assertSee(__('Alert history'), false)
        ->assertSee(__('FAQ'), false)
        ->assertSee(__('Messages'), false);
});

test('messages tab embeds inbox table', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    $this->get('http://'.$this->domain.'/member/help?tab=messages')
        ->assertSuccessful()
        ->assertSee(__('Inbox'), false);
});

test('faq tab shows localized entries', function () {
    Filament::setCurrentPanel('member');
    app()->setLocale('en');
    $this->actingAs($this->memberUser, 'tenant');

    $firstQuestion = MemberFaq::items()[0]['question'] ?? '';

    expect($firstQuestion)->toBe('When is my contribution collected?');

    $this->get('http://'.$this->domain.'/member/help?tab=faq')
        ->assertSuccessful()
        ->assertSee('ff-member-faq', false)
        ->assertSee($firstQuestion, false);
});

test('alert history tab lists member notification logs', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    NotificationLog::query()->create([
        'user_id' => $this->memberUser->id,
        'channel' => 'mail',
        'subject' => 'Contribution reminder',
        'body' => 'Your contribution is due.',
        'status' => 'sent',
        'sent_at' => now(),
    ]);

    $this->get('http://'.$this->domain.'/member/help?tab=alerts')
        ->assertSuccessful()
        ->assertSee(__('Alert history'), false)
        ->assertSee('Contribution reminder', false);
});

test('member faq loads arabic entries when locale is ar', function () {
    app()->setLocale('ar');

    $items = MemberFaq::items();

    expect($items)->not->toBeEmpty()
        ->and($items[0]['question'])->toContain('مساهمتي');
});

test('communications page switches to requests tab', function () {
    Filament::setCurrentPanel('member');
    $this->actingAs($this->memberUser, 'tenant');

    Livewire::test(CommunicationsPage::class)
        ->call('setTab', 'requests')
        ->assertSet('activeTab', 'requests');
});
