<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Notifications\Tenant\ContributionPostedNotification;
use App\Services\AccountingService;
use App\Services\ContributionCollectionCycleService;
use App\Services\ContributionCycleService;
use App\Services\ContributionService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    app()->setLocale('en');
    Filament::setCurrentPanel('member');

    Account::query()->delete();
    Contribution::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
    $this->service = app(ContributionService::class);
    $this->collection = app(ContributionCollectionCycleService::class);
    $this->cycles = app(ContributionCycleService::class);
});

function createMemberWithUserForPostedNotification(AccountingService $accounting, array $overrides = []): array
{
    $memberUser = User::create(array_merge([
        'name' => 'Posted Member',
        'email' => 'posted-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ], $overrides['user'] ?? []));

    $member = Member::create(array_merge([
        'user_id' => $memberUser->id,
        'member_number' => 'MEM-POST-'.uniqid(),
        'name' => 'Posted Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ], $overrides['member'] ?? []));

    $accounting->createMemberAccounts($member);

    return [$memberUser, $member];
}

test('manual contribution post notifies member with filament database format', function () {
    Notification::fake();

    [$memberUser, $member] = createMemberWithUserForPostedNotification($this->accounting);

    Account::masterCash()->update(['balance' => 5000]);
    $member->cashAccount->update(['balance' => 5000]);

    $contribution = $this->service->recordContribution($member, '2026-05-01');
    $contribution->update(['payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT]);

    $this->service->postContribution($contribution);

    Notification::assertSentTo(
        $memberUser,
        ContributionPostedNotification::class,
        function (ContributionPostedNotification $notification, array $channels) use ($memberUser, $contribution): bool {
            $payload = $notification->toDatabase($memberUser);

            return in_array('database', $channels, true)
                && ($payload['format'] ?? null) === 'filament'
                && ($payload['title'] ?? null) === __('Contribution posted')
                && $notification->contribution->id === $contribution->id;
        },
    );
});

test('collection cycle posting stores contribution posted notification in member bell', function () {
    [$memberUser, $member] = createMemberWithUserForPostedNotification($this->accounting);

    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->credit($member->cashAccount, 1000, 'Seed cash'),
    );

    [$month, $year] = $this->cycles->currentOpenPeriod();
    $this->collection->initializeOpenPeriod($month, $year);

    $contribution = Contribution::query()
        ->forPeriod($month, $year)
        ->where('member_id', $member->id)
        ->firstOrFail();

    expect($this->collection->attemptCollection($contribution))->toBe('collected');

    expect(
        $memberUser->fresh()
            ->notifications()
            ->where('data->format', 'filament')
            ->where('type', ContributionPostedNotification::class)
            ->count()
    )->toBe(1);
});

test('without posted notifications suppresses contribution posted emails during bulk import', function () {
    Notification::fake();

    [$memberUser, $member] = createMemberWithUserForPostedNotification($this->accounting);

    Account::masterCash()->update(['balance' => 5000]);
    $member->cashAccount->update(['balance' => 5000]);

    $contribution = $this->service->recordContribution($member, '2026-05-01');
    $contribution->update(['payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT]);

    ContributionService::withoutPostedNotifications(function () use ($contribution): void {
        $this->service->postContribution($contribution);
    });

    Notification::assertNothingSent();
});
