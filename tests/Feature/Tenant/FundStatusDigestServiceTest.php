<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\User;
use App\Notifications\Tenant\FundStatusDigestNotification;
use App\Services\AccountingService;
use App\Services\FundStatusDigestService;
use App\Services\Loans\LoanDelinquencyService;
use App\Support\AutomationScheduleSettings;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $this->service = app(FundStatusDigestService::class);
    app(LoanDelinquencyService::class)->forgetArrearsAggregateCaches();

    config([
        'webpush.vapid.public_key' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U',
        'webpush.vapid.private_key' => 'UUxI4O8-FbRqjAihg6f42nd_pmTQj2vmanuelys70Ho',
    ]);

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();
    FundPosting::query()->delete();
    MemberRequest::query()->delete();
    User::query()->where('is_admin', true)->delete();
    User::query()->where('email', 'like', '%fund-status-digest%')->delete();
});

function createDigestMember(AccountingService $accounting): Member
{
    $member = Member::create([
        'member_number' => 'MEM-FUNDSTATUS-'.uniqid(),
        'name' => 'Fund Status Digest Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting->createMemberAccounts($member);

    return $member->fresh();
}

test('fund status digest notifies admins via database and mail when enabled', function () {
    Notification::fake();

    $admin = User::create([
        'name' => 'Fund Status Digest Admin',
        'email' => 'fund-status-digest-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    expect(AutomationScheduleSettings::notifyFundStatusDigest())->toBeTrue();

    $member = createDigestMember(app(AccountingService::class));

    FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => now()->toDateString(),
        'amount' => 1000,
        'reference' => 'FD-TEST',
        'status' => 'pending',
    ]);

    MemberRequest::create([
        'requester_member_id' => $member->id,
        'type' => MemberRequest::TYPE_VOLUNTARY_CONTRIBUTION,
        'status' => MemberRequest::STATUS_PENDING,
        'payload' => ['amount' => 3000],
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => now()->subMonth(),
        'status' => 'overdue',
    ]);

    $notified = $this->service->notifyAdminsIfNeeded();

    expect($notified)->toBe(1);

    Notification::assertSentTo(
        $admin,
        FundStatusDigestNotification::class,
        function (FundStatusDigestNotification $notification, array $channels) use ($admin): bool {
            $databasePayload = $notification->toDatabase($admin);

            return in_array('database', $channels, true)
                && in_array('mail', $channels, true)
                && in_array(WebPushChannel::class, $channels, true)
                && ($databasePayload['format'] ?? null) === 'filament'
                && ($notification->digest['counts']['pending_deposits'] ?? null) === 1
                && ($notification->digest['counts']['pending_member_requests'] ?? null) === 1;
        },
    );
});
