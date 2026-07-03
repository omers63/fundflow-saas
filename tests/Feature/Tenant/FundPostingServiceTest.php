<?php

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Notifications\Tenant\FundPostingAcceptedNotification;
use App\Notifications\Tenant\FundPostingRejectedNotification;
use App\Notifications\Tenant\NewFundPostingNotification;
use App\Services\AccountingService;
use App\Services\ContributionCollectionCycleService;
use App\Services\FundPostingService;
use App\Support\ContributionCollectionStatus;
use Filament\Facades\Filament;
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
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
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
    Filament::setCurrentPanel('member');

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

    Notification::assertSentTo(
        $admin,
        NewFundPostingNotification::class,
        function (NewFundPostingNotification $notification, array $channels) use ($admin): bool {
            $payload = $notification->toDatabase($admin);
            $actionUrl = $payload['actions'][0]['url'] ?? '';

            return in_array('database', $channels, true)
                && ($payload['format'] ?? null) === 'filament'
                && str_contains($actionUrl, 'fund-postings')
                && str_contains((string) ($payload['body'] ?? ''), 'ff-notification-details');
        },
    );
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
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = $this->service->submit($member, 5000, '2026-05-10');

    AccountingService::withoutMemberCashCollection(
        fn () => $this->service->accept($posting, reviewedBy: $admin->id, remarks: 'Looks good'),
    );

    $posting->refresh();
    expect($posting->status)->toBe('accepted');
    expect($posting->admin_remarks)->toBe('Looks good');
    expect($posting->reviewed_at)->not->toBeNull();

    expect(Account::masterCash()->balance)->toBe('5000.00');
    expect($member->cashAccount->fresh()->balance)->toBe('5000.00');

    $postingLines = Transaction::query()
        ->where('reference_type', FundPosting::class)
        ->where('reference_id', $posting->id)
        ->get();

    expect($postingLines)->toHaveCount(1)
        ->and($postingLines->first()->type)->toBe('credit')
        ->and($postingLines->first()->account_id)->toBe($member->cashAccount->id)
        ->and(
            Transaction::query()
                ->where('reference_type', Transaction::class)
                ->whereIn('reference_id', $postingLines->pluck('id'))
                ->exists(),
        )->toBeFalse();

    $masterMirror = Transaction::query()
        ->where('account_id', Account::masterCash()->id)
        ->where('type', 'credit')
        ->latest('id')
        ->first();

    expect($masterMirror)->not->toBeNull()
        ->and($masterMirror->description)->toContain('John Doe')
        ->and($masterMirror->description)->not->toContain('John Doe (John Doe)')
        ->and(substr_count((string) $masterMirror->description, 'John Doe'))->toBe(1);
});

test('accepting deposit triggers arrear settlement for the member', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'MEM-ARREAR',
        'name' => 'Arrear Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $collection = Mockery::mock(ContributionCollectionCycleService::class)->makePartial();
    $collection->shouldReceive('onMemberCashIncreased')
        ->once()
        ->withArgs(fn (Member $settled): bool => $settled->id === $member->id);
    app()->instance(ContributionCollectionCycleService::class, $collection);

    $posting = $this->service->submit($member, 500, '2026-05-10');
    $this->service->accept($posting);
});

test('credit member cash with master mirror triggers arrear settlement', function () {
    $member = Member::create([
        'member_number' => 'MEM-MIRROR-ARREAR',
        'name' => 'Mirror Arrear',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $collection = Mockery::mock(ContributionCollectionCycleService::class)->makePartial();
    $collection->shouldReceive('onMemberCashIncreased')
        ->once()
        ->withArgs(fn (Member $settled): bool => $settled->id === $member->id);
    app()->instance(ContributionCollectionCycleService::class, $collection);

    $this->accounting->creditMemberCashWithMasterMirror(
        $member->cashAccount,
        500,
        'Deposit',
        '(mirror test)',
    );
});

test('accepted fund posting member line can be reversed without unbalanced journal error', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'MEM-0002',
        'name' => 'Jane Doe',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = $this->service->submit($member, 10_000, '2026-05-10');

    AccountingService::withoutMemberCashCollection(
        fn () => $this->service->accept($posting),
    );

    $memberLine = Transaction::query()
        ->where('reference_type', FundPosting::class)
        ->where('reference_id', $posting->id)
        ->where('account_id', $member->cashAccount->id)
        ->first();

    expect($memberLine)->not->toBeNull();

    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->createReversalEntry($memberLine, 'Posted in error'),
    );

    $member->cashAccount->refresh();
    Account::masterCash()->refresh();

    expect((float) $member->cashAccount->balance)->toBe(0.0)
        ->and((float) Account::masterCash()->balance)->toBe(0.0)
        ->and(
            Transaction::query()
                ->where('description', 'like', '%Unbalanced journal%')
                ->exists(),
        )->toBeFalse();
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
    Filament::setCurrentPanel('member');

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

    Notification::assertSentTo(
        $memberUser,
        FundPostingAcceptedNotification::class,
        function (FundPostingAcceptedNotification $notification, array $channels) use ($memberUser): bool {
            $payload = $notification->toDatabase($memberUser);

            return in_array('database', $channels, true)
                && ($payload['format'] ?? null) === 'filament'
                && str_contains((string) ($payload['body'] ?? ''), 'ff-notification-section');
        },
    );

    $posting2 = $this->service->submit($member, 500, '2026-05-13');
    $this->service->reject($posting2, remarks: 'Duplicate');

    Notification::assertSentTo(
        $memberUser,
        FundPostingRejectedNotification::class,
        function (FundPostingRejectedNotification $notification, array $channels) use ($memberUser): bool {
            $payload = $notification->toDatabase($memberUser);

            return in_array('database', $channels, true)
                && ($payload['format'] ?? null) === 'filament'
                && str_contains((string) ($payload['body'] ?? ''), 'ff-notification-details');
        },
    );
});

test('accepted deposit notification reports contribution settlement applied', function () {
    Notification::fake();
    Filament::setCurrentPanel('member');

    $memberUser = User::create([
        'name' => 'Settling Member',
        'email' => 'settling-member@test.com',
        'password' => bcrypt('password'),
        'is_admin' => false,
    ]);

    $member = Member::create([
        'user_id' => $memberUser->id,
        'member_number' => 'MEM-SETTLE',
        'name' => 'Settling Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    Contribution::create([
        'member_id' => $member->id,
        'period' => now()->subMonth()->startOfMonth(),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
    ]);

    $posting = $this->service->submit($member, 1000, now()->toDateString());
    $this->service->accept($posting);

    Notification::assertSentTo(
        $memberUser,
        FundPostingAcceptedNotification::class,
        function (FundPostingAcceptedNotification $notification, array $channels) use ($memberUser): bool {
            $payload = $notification->toDatabase($memberUser);

            return in_array('database', $channels, true)
                && ($payload['format'] ?? null) === 'filament'
                && $notification->settlement !== null
                && $notification->settlement->contributionsApplied >= 500.0
                && $notification->settlement->remainingCash <= 500.01;
        },
    );
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
