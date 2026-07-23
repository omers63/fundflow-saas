<?php

declare(strict_types=1);

use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Notifications\Tenant\ContributionDueNotification;
use App\Notifications\Tenant\MemberAnnouncementNotification;
use App\Support\CommunicationSettings;
use App\Support\PushEventSettings;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Setting::set(CommunicationSettings::GROUP, 'in_app_enabled', true);
    Setting::set(CommunicationSettings::GROUP, 'email_enabled', true);
    config([
        'webpush.vapid.public_key' => 'test-public',
        'webpush.vapid.private_key' => 'test-private',
    ]);
});

test('push is enabled by default for catalog events', function () {
    expect(PushEventSettings::enabledFor('contribution_due'))->toBeTrue()
        ->and(PushEventSettings::enabledFor('monthly_statement'))->toBeTrue();
});

test('disabling a push event removes web push from via channels', function () {
    Setting::set(PushEventSettings::GROUP, 'contribution_due', '0');

    $user = User::create([
        'name' => 'Push Event Member',
        'email' => 'push-event@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $channels = (new ContributionDueNotification(
        month: 5,
        year: 2026,
        amount: 100.0,
        deadline: now()->endOfMonth(),
        cashBalance: 50.0,
    ))->via($user);

    expect($channels)->not->toContain(WebPushChannel::class)
        ->and($channels)->toContain('database');
});

test('disabling announcement push removes web push from announcement via', function () {
    Setting::set(PushEventSettings::GROUP, 'member_announcement', '0');

    $user = User::create([
        'name' => 'Announcement Push Member',
        'email' => 'announce-push@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $channels = (new MemberAnnouncementNotification(
        title: 'Hello',
        body: 'World',
        sendInApp: true,
        sendEmail: false,
        sendSms: false,
    ))->via($user);

    expect($channels)->not->toContain(WebPushChannel::class)
        ->and($channels)->toContain('database');
});

test('push event form save persists enabled keys', function () {
    PushEventSettings::saveFromForm([
        'push_events_enabled' => ['monthly_statement', 'contribution_due'],
    ]);

    expect(PushEventSettings::enabledFor('monthly_statement'))->toBeTrue()
        ->and(PushEventSettings::enabledFor('contribution_due'))->toBeTrue()
        ->and(PushEventSettings::enabledFor('member_announcement'))->toBeFalse()
        ->and(PushEventSettings::enabledKeys())->toContain('monthly_statement')
        ->and(PushEventSettings::enabledKeys())->not->toContain('member_announcement');
});
