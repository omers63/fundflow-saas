<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Notifications\Tenant\LoanDefaultGuarantorNotification;
use App\Notifications\Tenant\LoanDefaultWarningNotification;
use App\Notifications\Tenant\LoanSettledNotification;
use App\Services\AccountingService;
use App\Services\Loans\LoanDefaultService;
use Carbon\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->where('is_master', true)->delete();
    LoanInstallment::query()->delete();
    Loan::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    Account::query()->firstOrCreate(
        ['is_master' => true, 'type' => 'cash'],
        ['name' => 'Master Cash', 'balance' => 50000, 'member_id' => null]
    );
    Account::query()->firstOrCreate(
        ['is_master' => true, 'type' => 'fund'],
        ['name' => 'Master Fund', 'balance' => 50000, 'member_id' => null]
    );
});

function createUserAndMemberForDefaults(AccountingService $accounting, array $memberOverrides = [], array $userOverrides = []): Member
{
    $user = User::query()->create(array_merge([
        'name' => 'Tenant User '.uniqid(),
        'email' => 'user-'.uniqid().'@example.test',
        'password' => Hash::make('password'),
        'is_admin' => false,
    ], $userOverrides));

    $member = Member::query()->create(array_merge([
        'user_id' => $user->id,
        'member_number' => 'MEM-'.uniqid(),
        'name' => 'Member '.uniqid(),
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(12),
        'status' => 'active',
    ], $memberOverrides));

    $accounting->createMemberAccounts($member);

    return $member->fresh();
}

test('process defaults warns borrower while within grace cycles', function () {
    Notification::fake();
    Setting::set('loan', 'default_grace_cycles', 2);

    $accounting = app(AccountingService::class);
    $borrower = createUserAndMemberForDefaults($accounting);

    $loan = Loan::query()->create([
        'member_id' => $borrower->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'amount_disbursed' => 10000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'status' => 'active',
        'late_repayment_count' => 0,
        'applied_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);

    LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 2000,
        'due_date' => Carbon::now()->subMonth(),
        'status' => 'overdue',
        'paid_by_guarantor' => false,
    ]);

    $result = app(LoanDefaultService::class)->processDefaults();

    expect($result['warned'])->toBe(1)
        ->and($result['debited_from_guarantor'])->toBe(0);

    Notification::assertSentTo($borrower->user, LoanDefaultWarningNotification::class);
});

test('process defaults debits guarantor when defaults exceed grace cycles', function () {
    Notification::fake();
    Setting::set('loan', 'default_grace_cycles', 1);
    Setting::set('late_fee', 'repayment_day_30', 50);

    $accounting = app(AccountingService::class);
    $borrower = createUserAndMemberForDefaults($accounting);
    $guarantor = createUserAndMemberForDefaults($accounting, ['member_number' => 'G-'.uniqid()]);
    $guarantor->fundAccount()->update(['balance' => 50000]);

    $loan = Loan::query()->create([
        'member_id' => $borrower->id,
        'guarantor_member_id' => $guarantor->id,
        'amount' => 12000,
        'amount_requested' => 12000,
        'amount_approved' => 12000,
        'amount_disbursed' => 12000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 2400,
        'total_repaid' => 0,
        'status' => 'active',
        'late_repayment_count' => 2,
        'applied_at' => now()->subMonths(2),
        'disbursed_at' => now()->subMonths(2),
    ]);

    $installment = LoanInstallment::query()->create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 2400,
        'due_date' => Carbon::now()->subMonths(4),
        'status' => 'overdue',
        'paid_by_guarantor' => false,
    ]);

    $guarantorFundBefore = (float) $guarantor->fundAccount()->first()->balance;

    $result = app(LoanDefaultService::class)->processDefaults();

    $installment->refresh();
    $guarantorFundAfter = (float) $guarantor->fresh()->fundAccount?->balance;

    expect($result['warned'])->toBe(0)
        ->and($result['debited_from_guarantor'])->toBe(1)
        ->and($installment->status)->toBe('paid')
        ->and($installment->paid_by_guarantor)->toBeTrue()
        ->and($guarantorFundAfter)->toBeLessThan($guarantorFundBefore);

    Notification::assertSentTo($guarantor->user, LoanDefaultGuarantorNotification::class);
});

test('check settlements marks ready active loan as completed and notifies borrower', function () {
    Notification::fake();

    $accounting = app(AccountingService::class);
    $borrower = createUserAndMemberForDefaults($accounting);
    $borrower->fundAccount()->update(['balance' => 3000]);

    $loan = Loan::query()->create([
        'member_id' => $borrower->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'amount_disbursed' => 10000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'status' => 'active',
        'master_portion' => 7000,
        'repaid_to_master' => 7000,
        'settlement_threshold' => 0.25,
        'applied_at' => now()->subMonths(3),
        'disbursed_at' => now()->subMonths(3),
    ]);

    $result = app(LoanDefaultService::class)->checkSettlements();

    expect($result)->toBe(1)
        ->and($loan->fresh()->status)->toBe('completed')
        ->and($loan->fresh()->settled_at)->not->toBeNull();

    Notification::assertSentTo($borrower->user, LoanSettledNotification::class);
});

test('check settlements skips loan that is not ready to settle', function () {
    Notification::fake();

    $accounting = app(AccountingService::class);
    $borrower = createUserAndMemberForDefaults($accounting);
    $borrower->fundAccount()->update(['balance' => 1000]);

    $loan = Loan::query()->create([
        'member_id' => $borrower->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'amount_disbursed' => 10000,
        'interest_rate' => 10,
        'term_months' => 6,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'status' => 'active',
        'master_portion' => 7000,
        'repaid_to_master' => 6500,
        'settlement_threshold' => 0.25,
        'applied_at' => now()->subMonths(3),
        'disbursed_at' => now()->subMonths(3),
    ]);

    $result = app(LoanDefaultService::class)->checkSettlements();

    expect($result)->toBe(0)
        ->and($loan->fresh()->status)->toBe('active')
        ->and($loan->fresh()->settled_at)->toBeNull();

    Notification::assertNothingSent();
});
