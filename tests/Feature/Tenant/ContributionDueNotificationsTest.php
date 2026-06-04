<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Notifications\Tenant\ContributionDueNotification;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('member');
    $this->cycles = app(ContributionCycleService::class);
    $this->accounting = app(AccountingService::class);

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

    expect($this->cycles->sendDueNotifications($month, $year))->toBe(1);

    Notification::assertSentTo(
        $memberUser,
        ContributionDueNotification::class,
        function (ContributionDueNotification $notification, array $channels) use ($memberUser, $month, $year): bool {
            $payload = $notification->toDatabase($memberUser);

            return in_array('database', $channels, true)
                && ($payload['format'] ?? null) === 'filament'
                && ($payload['title'] ?? null) === __('Contribution due')
                && $notification->month === $month
                && $notification->year === $year;
        },
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

    expect($this->cycles->sendDueNotifications($month, $year))->toBe(1);

    expect(
        $memberUser->fresh()
            ->notifications()
            ->where('data->format', 'filament')
            ->where('type', ContributionDueNotification::class)
            ->count()
    )->toBe(1);
});
