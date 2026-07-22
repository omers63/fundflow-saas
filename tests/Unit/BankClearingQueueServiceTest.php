<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Services\AccountingService;
use App\Services\BankClearingQueueService;
use App\Services\FundPostingService;
use App\Services\MasterExpenseDisbursementService;
use App\Support\BankClearing\BankClearingQueueFilter;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    BankTransaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 50_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 50_000, 'is_master' => true]);
    Account::create(['type' => 'expense', 'name' => 'Master Expense', 'balance' => 5_000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->queue = app(BankClearingQueueService::class);
    $this->accounting = app(AccountingService::class);
});

it('returns combined open queue counts', function () {
    $member = Member::create([
        'member_number' => 'MEM-Q-01',
        'name' => 'Queue Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = app(FundPostingService::class)->submit($member, 400, now()->toDateString());
    app(FundPostingService::class)->accept($posting);

    $statement = BankStatement::create([
        'filename' => 'queue-count.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Imported queue line',
        'amount' => 900,
        'status' => 'imported',
        'hash' => md5('queue-count-import'),
    ]);

    $counts = $this->queue->counts();

    expect($counts['bank_file'])->toBe(1)
        ->and($counts['operations'])->toBe(1)
        ->and($counts['all'])->toBe(2)
        ->and($this->queue->openCount())->toBe(2);
});

it('scopes open items by queue filter', function () {
    $member = Member::create([
        'member_number' => 'MEM-Q-02',
        'name' => 'Filter Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = app(FundPostingService::class)->submit($member, 500, now()->toDateString());
    app(FundPostingService::class)->accept($posting);
    $operationalId = $posting->fresh()->bankTransaction->id;

    $statement = BankStatement::create([
        'filename' => 'queue-filter.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $bankFile = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Imported filter line',
        'amount' => 1_200,
        'status' => 'imported',
        'hash' => md5('queue-filter-import'),
    ]);

    $allIds = $this->queue->openItemsQuery(BankClearingQueueFilter::All)->pluck('id')->all();
    $bankFileIds = $this->queue->openItemsQuery(BankClearingQueueFilter::BankFile)->pluck('id')->all();
    $operationsIds = $this->queue->openItemsQuery(BankClearingQueueFilter::Operations)->pluck('id')->all();

    expect($allIds)->toContain($operationalId, $bankFile->id)
        ->and($bankFileIds)->toBe([$bankFile->id])
        ->and($operationsIds)->toBe([$operationalId]);
});

it('filters open queue items by kind', function () {
    $member = Member::create([
        'member_number' => 'MEM-Q-03',
        'name' => 'Kind Filter Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = app(FundPostingService::class)->submit($member, 500, now()->toDateString());
    app(FundPostingService::class)->accept($posting);
    $depositId = $posting->fresh()->bankTransaction->id;

    $statement = BankStatement::create([
        'filename' => 'queue-kind.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $bankFile = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Kind filter import',
        'amount' => 1_200,
        'status' => 'imported',
        'hash' => md5('queue-kind-import'),
    ]);

    app(AccountingService::class)->fundReserveAccountFromMasterFund(
        Account::masterExpense(),
        1_000,
        'Kind filter reserve',
    );

    app(MasterExpenseDisbursementService::class)->disburse(
        Account::masterExpense(),
        250,
        'Kind filter expense',
    );

    $expenseId = BankTransaction::query()->whereNotNull('expense_disbursement_id')->value('id');

    $open = $this->queue->openItemsQuery(BankClearingQueueFilter::All);

    expect($this->queue->applyKindFilter(clone $open, 'bank_import')->pluck('id')->all())->toBe([$bankFile->id])
        ->and($this->queue->applyKindFilter(clone $open, 'deposit')->pluck('id')->all())->toBe([$depositId])
        ->and($this->queue->applyKindFilter(clone $open, 'expense')->pluck('id')->all())->toBe([$expenseId])
        ->and($this->queue->applySliceFilter(clone $open, 'operations')->pluck('id')->all())->toContain($depositId, $expenseId)
        ->and($this->queue->applySliceFilter(clone $open, 'bank_file')->pluck('id')->all())->toBe([$bankFile->id]);
});

it('classifies queue slices for records', function () {
    app(AccountingService::class)->fundReserveAccountFromMasterFund(
        Account::masterExpense(),
        1_000,
        'Queue slice reserve',
    );

    app(MasterExpenseDisbursementService::class)->disburse(
        Account::masterExpense(),
        300,
        'Queue slice expense',
    );

    $operational = BankTransaction::query()->whereNotNull('expense_disbursement_id')->firstOrFail();

    $statement = BankStatement::create([
        'filename' => 'queue-slice.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $bankFile = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Slice import',
        'amount' => 300,
        'status' => 'imported',
        'hash' => md5('queue-slice-import'),
    ]);

    expect($this->queue->sliceForRecord($operational))->toBe('operations')
        ->and($this->queue->sliceForRecord($bankFile))->toBe('bank_file')
        ->and($this->queue->primaryActionForRecord($operational))->toBe('matchToBankLine')
        ->and($this->queue->primaryActionForRecord($bankFile))->toBe('postAs');
});
