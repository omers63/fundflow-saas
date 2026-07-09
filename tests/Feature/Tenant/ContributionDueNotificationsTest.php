<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberCommunicationPreference;
use App\Models\Tenant\User;
use App\Notifications\Tenant\ContributionDueNotification;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Tenant\NotificationPreferenceService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('member');
    $this->cycles = app(ContributionCycleService::class);
    $this->accounting = app(AccountingService::class);

    config([
        'webpush.vapid.public_key' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U',
        'webpush.vapid.private_key' => 'UUxI4O8-FbRqjAihg6f42nd_pmTQj2vmanuelys70Ho',
    ]);

    Account::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
});

test('contribution due notification uses filament database format for member bell', function () {
    Notification::fake();

    $memberUser = User::create([
        'name' => 'Due Member',
        'email' => 'due-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $memberUser->id,
        'member_number' => 'MEM-DUE-01',
        'name' => 'Due Member',
        'monthly_contribution_amount' => 1500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 500]);

    [$month, $year] = $this->cycles->currentOpenPeriod();

    expect($this->cycles->sendDueNotifications($month, $year)['notified'])->toBe(1);

    Notification::assertSentTo(
        $memberUser,
        ContributionDueNotification::class,
        function (ContributionDueNotification $notification, array $channels) use ($memberUser, $month, $year): bool {
            $payload = $notification->toDatabase($memberUser);

            return in_array('database', $channels, true)
                && in_array(WebPushChannel::class, $channels, true)
                && ! in_array('mail', $channels, true)
                && ($payload['format'] ?? null) === 'filament'
                && ($payload['title'] ?? null) === __('Contribution due')
                && $notification->month === $month
                && $notification->year === $year;
        },
    );
});

test('contribution due notification omits push when member disables the channel', function () {
    Notification::fake();

    $memberUser = User::create([
        'name' => 'No Push Member',
        'email' => 'no-push-due@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $memberUser->id,
        'member_number' => 'MEM-DUE-03',
        'name' => 'No Push Member',
        'monthly_contribution_amount' => 1500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    MemberCommunicationPreference::saveFor(
        $memberUser->id,
        NotificationPreferenceService::CONTRIBUTIONS,
        [NotificationPreferenceService::CH_IN_APP],
        [NotificationPreferenceService::CH_IN_APP],
    );

    [$month, $year] = $this->cycles->currentOpenPeriod();

    expect($this->cycles->sendDueNotifications($month, $year)['notified'])->toBe(1);

    Notification::assertSentTo(
        $memberUser,
        ContributionDueNotification::class,
        fn (ContributionDueNotification $notification, array $channels): bool => in_array('database', $channels, true)
        && ! in_array(WebPushChannel::class, $channels, true)
        && ! in_array('mail', $channels, true),
    );
});

test('contribution due notification is stored in the member notifications table', function () {
    $memberUser = User::create([
        'name' => 'Stored Due Member',
        'email' => 'stored-due-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $memberUser->id,
        'member_number' => 'MEM-DUE-02',
        'name' => 'Stored Due Member',
        'monthly_contribution_amount' => 2000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    [$month, $year] = $this->cycles->currentOpenPeriod();

    expect($this->cycles->sendDueNotifications($month, $year)['notified'])->toBe(1);

    expect(
        $memberUser->fresh()
            ->notifications()
            ->where('data->format', 'filament')
            ->where('type', ContributionDueNotification::class)
            ->count()
    )->toBe(1);
});

test('parent contribution due amount includes dependent allocation shortfall', function () {
    Notification::fake();

    $parentUser = User::create([
        'name' => 'Parent Due',
        'email' => 'parent-due@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $parent = Member::create([
        'user_id' => $parentUser->id,
        'member_number' => 'MEM-DUE-P01',
        'name' => 'Parent Due',
        'email' => 'parent-due@test.com',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($parent);
    $parent->cashAccount->update(['balance' => 0]);

    $dependent = Member::create([
        'member_number' => 'MEM-DUE-D01',
        'name' => 'Child Due',
        'email' => 'child-due@test.com',
        'parent_member_id' => $parent->id,
        'household_email' => 'parent-due@test.com',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($dependent);
    $dependent->cashAccount->update(['balance' => 0]);

    [$month, $year] = $this->cycles->currentOpenPeriod();
    $deadline = $this->cycles->deadline($month, $year)->copy()->startOfDay();
    $expectedAmount = $this->cycles->dueNotificationAmountForMember($parent->fresh(), $month, $year);

    expect($expectedAmount)->toBe(1500.0);

    expect($this->cycles->sendDueNotifications($month, $year)['notified'])->toBe(1);

    Notification::assertSentTo(
        $parentUser,
        ContributionDueNotification::class,
        function (ContributionDueNotification $notification) use ($parentUser, $expectedAmount, $deadline): bool {
            $payload = $notification->toArray($parentUser);
            $push = $notification->toWebPush($parentUser, $notification)->toArray();

            return abs($notification->amount - $expectedAmount) < 0.00001
                && $notification->memberName === 'Parent Due'
                && ($payload['member_name'] ?? null) === 'Parent Due'
                && str_contains((string) ($payload['body'] ?? ''), number_format($expectedAmount, 2))
                && str_contains((string) ($payload['body'] ?? ''), $deadline->translatedFormat('j M Y'))
                && str_starts_with((string) ($push['title'] ?? ''), 'Parent Due — ')
                && $notification->deadline->equalTo($deadline);
        },
    );
});

test('contribution due notification uses cycle due end date not wall clock plus days', function () {
    Notification::fake();

    $memberUser = User::create([
        'name' => 'Deadline Member',
        'email' => 'deadline-due@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $memberUser->id,
        'member_number' => 'MEM-DUE-04',
        'name' => 'Deadline Member',
        'monthly_contribution_amount' => 750,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    [$month, $year] = $this->cycles->currentOpenPeriod();
    $cycleDeadline = $this->cycles->deadline($month, $year)->copy()->startOfDay();
    $wrongWallClockDeadline = now()->addDays(5)->startOfDay();

    expect($this->cycles->sendDueNotifications($month, $year)['notified'])->toBe(1);

    Notification::assertSentTo(
        $memberUser,
        ContributionDueNotification::class,
        function (ContributionDueNotification $notification) use ($memberUser, $cycleDeadline, $wrongWallClockDeadline): bool {
            $body = (string) ($notification->toArray($memberUser)['body'] ?? '');

            return $notification->deadline->equalTo($cycleDeadline)
                && str_contains($body, $cycleDeadline->translatedFormat('j M Y'))
                && (
                    $cycleDeadline->equalTo($wrongWallClockDeadline)
                    || ! str_contains($body, $wrongWallClockDeadline->translatedFormat('j M Y'))
                );
        },
    );
});
