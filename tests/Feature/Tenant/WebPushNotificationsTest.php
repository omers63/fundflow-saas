<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberAnnouncement;
use App\Models\Tenant\User;
use App\Notifications\Tenant\AdminDirectMessageNotification;
use App\Notifications\Tenant\ContributionDueNotification;
use App\Notifications\Tenant\FundPostingAcceptedNotification;
use App\Notifications\Tenant\FundPostingRejectedNotification;
use App\Notifications\Tenant\MemberAnnouncementNotification;
use App\Notifications\Tenant\MemberDirectMessageNotification;
use App\Notifications\Tenant\NewFundPostingNotification;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\FundPostingService;
use App\Services\Tenant\DirectMessagingService;
use App\Services\Tenant\MemberAnnouncementService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    config([
        'webpush.vapid.public_key' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U',
        'webpush.vapid.private_key' => 'UUxI4O8-FbRqjAihg6f42nd_pmTQj2vmanuelys70Ho',
    ]);

    Notification::fake();

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-webpush@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->memberUser = User::create([
        'name' => 'Member',
        'email' => 'member-webpush@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-WP01',
        'name' => 'Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->member);
});

test('new deposit request includes web push for admin', function () {
    Filament::setCurrentPanel('member');

    app(FundPostingService::class)->submit($this->member, 1000, now()->toDateString());

    Notification::assertSentTo(
        $this->admin,
        NewFundPostingNotification::class,
        fn (NewFundPostingNotification $notification, array $channels): bool => in_array('database', $channels, true)
        && in_array(WebPushChannel::class, $channels, true),
    );
});

test('deposit accept and reject include web push for member', function () {
    $posting = app(FundPostingService::class)->submit($this->member, 1000, now()->toDateString());

    Notification::fake();

    app(FundPostingService::class)->accept($posting, $this->admin->id);

    Notification::assertSentTo(
        $this->memberUser,
        FundPostingAcceptedNotification::class,
        fn (FundPostingAcceptedNotification $notification, array $channels): bool => in_array(WebPushChannel::class, $channels, true)
        && isset($notification->toArray($this->memberUser)['fund_posting_id']),
    );

    Notification::fake();

    $rejected = app(FundPostingService::class)->submit($this->member, 500, now()->toDateString());
    app(FundPostingService::class)->reject($rejected, $this->admin->id, 'Invalid reference');

    Notification::assertSentTo(
        $this->memberUser,
        FundPostingRejectedNotification::class,
        fn (FundPostingRejectedNotification $notification, array $channels): bool => in_array(WebPushChannel::class, $channels, true),
    );
});

test('contribution due reminder includes web push for member', function () {
    $this->member->cashAccount->update(['balance' => 250]);

    [$month, $year] = app(ContributionCycleService::class)->currentOpenPeriod();

    expect(app(ContributionCycleService::class)->sendDueNotifications($month, $year)['notified'])->toBe(1);

    Notification::assertSentTo(
        $this->memberUser,
        ContributionDueNotification::class,
        function (ContributionDueNotification $notification, array $channels): bool {
            $push = $notification->toWebPush($this->memberUser, $notification)->toArray();

            return in_array(WebPushChannel::class, $channels, true)
                && filled($notification->toArray($this->memberUser)['url'] ?? null)
                && str_starts_with((string) ($push['title'] ?? ''), 'Member — ');
        },
    );
});

test('direct messages include web push for member and admin', function () {
    $messaging = app(DirectMessagingService::class);

    $messaging->sendAdminToMember($this->member, $this->admin, 'Please review your statement');

    Notification::assertSentTo(
        $this->memberUser,
        MemberDirectMessageNotification::class,
        fn (MemberDirectMessageNotification $notification, array $channels): bool => in_array(WebPushChannel::class, $channels, true)
        && filled($notification->toArray($this->memberUser)['url'] ?? null),
    );

    Notification::fake();

    $messaging->notifyAdminsOfMemberMessage($this->memberUser, 'Question', 'When is my next EMI due?');

    Notification::assertSentTo(
        $this->admin,
        AdminDirectMessageNotification::class,
        fn (AdminDirectMessageNotification $notification, array $channels): bool => in_array(WebPushChannel::class, $channels, true),
    );
});

test('email announcements include web push for member', function () {
    $announcement = MemberAnnouncement::query()->create([
        'created_by_user_id' => $this->admin->id,
        'audience' => MemberAnnouncement::AUDIENCE_ALL_ACTIVE,
        'title_en' => 'Pool meeting',
        'body_en' => 'Annual meeting next week.',
        'channels' => [MemberAnnouncement::CHANNEL_EMAIL],
    ]);

    app(MemberAnnouncementService::class)->dispatch($announcement, $this->admin);

    Notification::assertSentTo(
        $this->memberUser,
        MemberAnnouncementNotification::class,
        fn (MemberAnnouncementNotification $notification, array $channels): bool => in_array('mail', $channels, true)
        && in_array(WebPushChannel::class, $channels, true),
    );
});

test('in-app announcements include web push for member', function () {
    $announcement = MemberAnnouncement::query()->create([
        'created_by_user_id' => $this->admin->id,
        'audience' => MemberAnnouncement::AUDIENCE_ALL_ACTIVE,
        'title_en' => 'Policy update',
        'body_en' => 'Updated contribution policy.',
        'channels' => [MemberAnnouncement::CHANNEL_IN_APP],
    ]);

    app(MemberAnnouncementService::class)->dispatch($announcement, $this->admin);

    Notification::assertSentTo(
        $this->memberUser,
        MemberAnnouncementNotification::class,
        fn (MemberAnnouncementNotification $notification, array $channels): bool => in_array('database', $channels, true)
            && in_array(WebPushChannel::class, $channels, true),
    );
});
