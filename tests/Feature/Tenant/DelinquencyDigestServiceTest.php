<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Notifications\Tenant\DelinquencyDigestNotification;
use App\Services\AccountingService;
use App\Services\Loans\DelinquencyDigestService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    $this->digest = app(DelinquencyDigestService::class);

    Account::query()->delete();
    Member::query()->delete();
    Loan::query()->delete();
    LoanInstallment::query()->delete();
    User::query()->delete();
});

function createMemberForDigest(AccountingService $accounting, array $overrides = []): Member
{
    $member = Member::create(array_merge([
        'member_number' => 'MEM-' . uniqid(),
        'name' => 'Digest Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ], $overrides));

    $accounting->createMemberAccounts($member);

    return $member->fresh();
}

test('delinquency digest notifies admins via database and mail when email is set', function () {
    Notification::fake();

    $admin = User::create([
        'name' => 'Digest Admin',
        'email' => 'digest-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $member = createMemberForDigest(app(AccountingService::class));

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

    $notified = $this->digest->notifyAdminsIfNeeded();

    expect($notified)->toBe(1);

    Notification::assertSentTo(
        $admin,
        DelinquencyDigestNotification::class,
        function (DelinquencyDigestNotification $notification, array $channels) use ($admin): bool {
            $databasePayload = $notification->toDatabase($admin);

            return in_array('database', $channels, true)
                && in_array('mail', $channels, true)
                && ($databasePayload['format'] ?? null) === 'filament'
                && (
                    str_starts_with($notification->delinquencyUrl, '/')
                    || str_starts_with($notification->delinquencyUrl, 'http')
                );
        },
    );
});

test('delinquency digest database payload appears in filament notification bell query', function () {
    $admin = User::create([
        'name' => 'Digest Admin',
        'email' => 'digest-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $member = createMemberForDigest(app(AccountingService::class));

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

    expect($this->digest->notifyAdminsIfNeeded())->toBe(1);

    expect(
        $admin->fresh()
            ->notifications()
            ->where('data->format', 'filament')
            ->where('type', DelinquencyDigestNotification::class)
            ->count()
    )->toBe(1);
});

test('delinquency digest uses database only when admin has no email', function () {
    Notification::fake();

    $admin = User::create([
        'name' => 'Digest Admin',
        'email' => '',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $member = createMemberForDigest(app(AccountingService::class));

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

    $this->digest->notifyAdminsIfNeeded();

    Notification::assertSentTo(
        $admin,
        DelinquencyDigestNotification::class,
        fn(DelinquencyDigestNotification $notification, array $channels): bool => $channels === ['database']
        && ($notification->toDatabase($admin)['format'] ?? null) === 'filament',
    );
});

test('delinquency digest is skipped when there is nothing to report', function () {
    Notification::fake();

    User::create([
        'name' => 'Digest Admin',
        'email' => 'digest-admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    expect($this->digest->notifyAdminsIfNeeded())->toBe(0);

    Notification::assertNothingSent();
});
