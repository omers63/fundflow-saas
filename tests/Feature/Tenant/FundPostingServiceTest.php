<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Notifications\Tenant\FundPostingAcceptedNotification;
use App\Notifications\Tenant\FundPostingRejectedNotification;
use App\Notifications\Tenant\NewFundPostingNotification;
use App\Services\AccountingService;
use App\Services\FundPostingService;
use Illuminate\Support\Facades\Notification;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    $this->accounting = app(AccountingService::class);
    $this->service = app(FundPostingService::class);

    Account::query()->delete();
    Member::query()->delete();
    FundPosting::query()->delete();
    BankTransaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
});

test('submit creates a pending fund posting', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'John Doe',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = $this->service->submit(
        member: $member,
        amount: 5000,
        postingDate: '2026-05-10',
        reference: 'TXN-123',
        comments: 'Monthly contribution',
    );

    expect($posting->status)->toBe('pending');
    expect($posting->amount)->toBe('5000.00');
    expect($posting->reference)->toBe('TXN-123');
    expect($posting->member_id)->toBe($member->id);
});

test('submit creates an uncleared bank transaction', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'John Doe',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = $this->service->submit($member, 5000, '2026-05-10');

    $bankTxn = BankTransaction::where('fund_posting_id', $posting->id)->first();

    expect($bankTxn)->not->toBeNull();
    expect($bankTxn->is_cleared)->toBeFalse();
    expect($bankTxn->amount)->toBe('5000.00');
    expect($bankTxn->member_id)->toBe($member->id);
    expect($bankTxn->status)->toBe('imported');
});

test('submit notifies admin users', function () {
    Notification::fake();

    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'John Doe',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $this->service->submit($member, 5000, '2026-05-10');

    Notification::assertSentTo($admin, NewFundPostingNotification::class);
});

test('accept credits master cash and member cash', function () {
    Notification::fake();

    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'John Doe',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = $this->service->submit($member, 5000, '2026-05-10');
    $this->service->accept($posting, reviewedBy: $admin->id, remarks: 'Looks good');

    $posting->refresh();
    expect($posting->status)->toBe('accepted');
    expect($posting->admin_remarks)->toBe('Looks good');
    expect($posting->reviewed_at)->not->toBeNull();

    expect(Account::masterCash()->balance)->toBe('5000.00');
    expect($member->cashAccount->fresh()->balance)->toBe('5000.00');
});

test('accept marks bank transaction as posted', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'John Doe',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = $this->service->submit($member, 5000, '2026-05-10');
    $this->service->accept($posting);

    $bankTxn = $posting->bankTransaction->fresh();
    expect($bankTxn->status)->toBe('posted');
    expect($bankTxn->is_cleared)->toBeFalse();
});

test('reject updates posting status and ignores bank transaction', function () {
    Notification::fake();

    $admin = User::create([
        'name' => 'Admin',
        'email' => 'admin@test.com',
        'password' => bcrypt('password'),
        'is_admin' => true,
    ]);

    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'John Doe',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = $this->service->submit($member, 5000, '2026-05-10');
    $this->service->reject($posting, reviewedBy: $admin->id, remarks: 'Invalid receipt');

    $posting->refresh();
    expect($posting->status)->toBe('rejected');
    expect($posting->admin_remarks)->toBe('Invalid receipt');

    $bankTxn = $posting->bankTransaction->fresh();
    expect($bankTxn->status)->toBe('ignored');

    expect(Account::masterCash()->balance)->toBe('0.00');
});

test('accept and reject notify member user', function () {
    Notification::fake();

    $memberUser = User::create([
        'name' => 'Member User',
        'email' => 'member-posting@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $memberUser->id,
        'member_number' => 'MEM-N01',
        'name' => 'Member User',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = $this->service->submit($member, 1000, '2026-05-12');
    $this->service->accept($posting);

    Notification::assertSentTo($memberUser, FundPostingAcceptedNotification::class);

    $posting2 = $this->service->submit($member, 500, '2026-05-13');
    $this->service->reject($posting2, remarks: 'Duplicate');

    Notification::assertSentTo($memberUser, FundPostingRejectedNotification::class);
});

test('clear transaction matches uncleared with imported', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'MEM-0001',
        'name' => 'John Doe',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = $this->service->submit($member, 5000, '2026-05-10');
    $this->service->accept($posting);

    $unclearedTxn = $posting->bankTransaction->fresh();
    expect($unclearedTxn->is_cleared)->toBeFalse();

    $importedTxn = BankTransaction::create([
        'bank_statement_id' => $unclearedTxn->bank_statement_id,
        'transaction_date' => '2026-05-10',
        'description' => 'Bank import matching',
        'amount' => 5000,
        'status' => 'imported',
        'hash' => md5('imported-match-'.microtime()),
        'is_cleared' => true,
        'cleared_at' => now(),
    ]);

    $this->service->clearTransaction($unclearedTxn, $importedTxn);

    expect($unclearedTxn->fresh()->is_cleared)->toBeTrue();
    expect($unclearedTxn->fresh()->cleared_at)->not->toBeNull();
    expect($importedTxn->fresh()->is_cleared)->toBeTrue();
    expect($importedTxn->fresh()->fund_posting_id)->toBe($posting->id);
});
