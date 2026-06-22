<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Notifications\Tenant\LoanApprovedNotification;
use App\Notifications\Tenant\LoanRejectedNotification;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\LoanService;
use App\Support\ContributionCollectionStatus;
use Carbon\Carbon;
use Illuminate\Support\Facades\Notification;
use NotificationChannels\WebPush\WebPushChannel;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Notification::fake();

    config([
        'webpush.vapid.public_key' => 'BEl62iUYgUivxIkv69yViEuiBIa-Ib9-SkvMeAtA3LFgDzkrxZJjSgSnfckjBJuBkr3qBUYIHBQFLXYp5Nksh8U',
        'webpush.vapid.private_key' => 'UUxI4O8-FbRqjAihg6f42nd_pmTQj2vmanuelys70Ho',
    ]);

    Account::query()->delete();
    Loan::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
    Account::masterFund()->update(['balance' => 100_000]);
    Account::masterCash()->update(['balance' => 100_000]);

    $this->memberUser = User::create([
        'name' => 'Borrower',
        'email' => 'borrower-webpush@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->member = Member::create([
        'user_id' => $this->memberUser->id,
        'member_number' => 'MEM-WEBPUSH',
        'name' => 'Borrower Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($this->member);
    $this->member->fundAccount()->update(['balance' => 30_000]);
    $this->member->cashAccount()->update(['balance' => 30_000]);

    $cycles = app(ContributionCycleService::class);
    [$openMonth, $openYear] = $cycles->currentOpenPeriod();
    $cursor = $this->member->joined_at->copy()->startOfMonth();

    while ($cursor->lte(Carbon::create($openYear, $openMonth, 1)->endOfMonth())) {
        $month = (int) $cursor->month;
        $year = (int) $cursor->year;

        if ((float) $this->member->monthly_contribution_amount > 0 && ! $this->member->isExemptFromContributions($month, $year)) {
            Contribution::create([
                'member_id' => $this->member->id,
                'period' => Contribution::periodDate($month, $year),
                'amount' => $this->member->monthly_contribution_amount,
                'amount_due' => $this->member->monthly_contribution_amount,
                'amount_collected' => $this->member->monthly_contribution_amount,
                'status' => 'posted',
                'collection_status' => ContributionCollectionStatus::COLLECTED,
                'posted_at' => $cursor->copy()->endOfMonth(),
                'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
                'is_late' => false,
            ]);
        }

        $cursor->addMonthNoOverflow();
    }

    $this->service = app(LoanService::class);
});

test('loan approval includes web push channel for member', function () {
    $loan = $this->service->applyForLoan($this->member, 10_000);
    $this->service->approveLoan($loan, 10_000);

    Notification::assertSentTo(
        $this->memberUser,
        LoanApprovedNotification::class,
        function (LoanApprovedNotification $notification, array $channels) use ($loan): bool {
            $payload = $notification->toArray($this->memberUser);

            return in_array('database', $channels, true)
                && in_array(WebPushChannel::class, $channels, true)
                && ($payload['loan_id'] ?? null) === $loan->id;
        },
    );
});

test('loan rejection includes web push channel for member', function () {
    $loan = $this->service->applyForLoan($this->member, 10_000);
    $this->service->rejectLoan($loan, 'Insufficient documentation');

    Notification::assertSentTo(
        $this->memberUser,
        LoanRejectedNotification::class,
        function (LoanRejectedNotification $notification, array $channels) use ($loan): bool {
            $payload = $notification->toArray($this->memberUser);

            return in_array('database', $channels, true)
                && in_array(WebPushChannel::class, $channels, true)
                && ($payload['loan_id'] ?? null) === $loan->id
                && ($payload['body'] ?? '') === 'Insufficient documentation';
        },
    );
});
