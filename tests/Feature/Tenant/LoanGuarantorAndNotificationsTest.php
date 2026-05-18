<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Notifications\Channels\SmsChannel;
use App\Notifications\Tenant\GuarantorLoanApplicationNotification;
use App\Notifications\Tenant\LoanSubmittedNotification;
use App\Notifications\Tenant\NewLoanApplicationNotification;
use App\Services\AccountingService;
use App\Services\Loans\LoanLifecycleService;
use App\Support\LoanSettings;
use App\Support\MemberNotificationChannels;
use App\Support\NotificationSettings;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Notification::fake();

    Account::query()->delete();
    Loan::query()->delete();
    Member::query()->delete();
    User::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Admin',
        'email' => 'admin-guarantor@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $this->guarantorUser = User::create([
        'name' => 'Guarantor User',
        'email' => 'guarantor@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->guarantor = Member::create([
        'user_id' => $this->guarantorUser->id,
        'member_number' => 'MEM-G001',
        'name' => 'Guarantor User',
        'phone' => '+966500000001',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
    ]);

    $this->borrowerUser = User::create([
        'name' => 'Borrower',
        'email' => 'borrower@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $this->borrower = Member::create([
        'user_id' => $this->borrowerUser->id,
        'member_number' => 'MEM-B001',
        'name' => 'Borrower',
        'phone' => '+966500000002',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($this->guarantor);
    app(AccountingService::class)->createMemberAccounts($this->borrower);

    // Fund balance eligible but below loan request amount (guarantor required)
    app(AccountingService::class)->credit($this->borrower->fundAccount, 8000, 'Seed fund');
    app(AccountingService::class)->credit($this->guarantor->fundAccount, 8000, 'Seed fund');
});

test('loan apply requires guarantor when amount exceeds fund balance', function () {
    LoanSettings::save(['require_guarantor_above_fund_balance' => true]);

    $lifecycle = app(LoanLifecycleService::class);

    expect(fn () => $lifecycle->applyForLoan($this->borrower, 15000, 'Test purpose'))
        ->toThrow(InvalidArgumentException::class);

    $loan = $lifecycle->applyForLoan(
        $this->borrower,
        15000,
        'Test purpose',
        $this->guarantor->id,
        false,
        true,
        'Witness One',
        '+966511111111',
    );

    expect($loan->guarantor_member_id)->toBe($this->guarantor->id)
        ->and($loan->witness1_name)->toBe('Witness One');

    Notification::assertSentTo($this->borrowerUser, LoanSubmittedNotification::class);
    Notification::assertSentTo($this->guarantorUser, GuarantorLoanApplicationNotification::class);
    Notification::assertSentTo($this->admin, NewLoanApplicationNotification::class);
});

test('member notification channels include sms when configured', function () {
    NotificationSettings::save([
        'sms_enabled' => true,
        'twilio_account_sid' => 'ACtest',
        'twilio_auth_token' => 'token',
        'twilio_sms_from' => '+10000000000',
    ]);

    $channels = MemberNotificationChannels::resolve($this->borrowerUser);

    expect($channels)->toContain('database')
        ->and($channels)->toContain(SmsChannel::class);
});
