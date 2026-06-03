<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Services\AccountDetailInsightsService;
use App\Services\AccountingService;
use App\Services\BankClearingMatchService;
use App\Services\FundPostingService;
use App\Services\MasterExpenseDisbursementService;
use App\Services\MasterFeeDisbursementService;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    Account::query()->delete();
    BankTransaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 50_000, 'is_master' => true]);
    Account::create(['type' => 'expense', 'name' => 'Master Expense', 'balance' => 5_000, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 3_000, 'is_master' => true]);

    $this->matching = app(BankClearingMatchService::class);
    $this->accounting = app(AccountingService::class);
});

test('pending operational clearance scopes to the correct master account type', function () {
    $member = Member::create([
        'member_number' => 'MEM-SCOPE-01',
        'name' => 'Scope Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = app(FundPostingService::class)->submit($member, 400, now()->toDateString());
    app(FundPostingService::class)->accept($posting);
    $depositLine = $posting->fresh()->bankTransaction;

    app(MasterExpenseDisbursementService::class)->disburse(
        Account::masterExpense(),
        250,
        'Scope expense',
    );
    $expenseLine = BankTransaction::query()->whereNotNull('expense_disbursement_id')->first();

    app(MasterFeeDisbursementService::class)->disburse(
        Account::masterFees(),
        175,
        'Scope fee',
    );
    $feeLine = BankTransaction::query()->whereNotNull('fee_disbursement_id')->first();

    $cashScope = $this->matching
        ->applyPendingOperationalClearanceScopeForMasterAccount(BankTransaction::query(), Account::masterCash())
        ->pluck('id');
    $expenseScope = $this->matching
        ->applyPendingOperationalClearanceScopeForMasterAccount(BankTransaction::query(), Account::masterExpense())
        ->pluck('id');
    $feesScope = $this->matching
        ->applyPendingOperationalClearanceScopeForMasterAccount(BankTransaction::query(), Account::masterFees())
        ->pluck('id');

    expect($cashScope)->toContain($depositLine->id)
        ->and($cashScope)->not->toContain($expenseLine->id, $feeLine->id)
        ->and($expenseScope)->toContain($expenseLine->id)
        ->and($expenseScope)->not->toContain($depositLine->id, $feeLine->id)
        ->and($feesScope)->toContain($feeLine->id)
        ->and($feesScope)->not->toContain($depositLine->id, $expenseLine->id)
        ->and($this->matching->pendingOperationalClearanceCountForMasterAccount(
            Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true])
        ))->toBe(0);
});

test('bank lines awaiting posting scope only includes real imported statement lines', function () {
    $statement = BankStatement::create([
        'filename' => 'scope-import.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'CSV inflow',
        'amount' => 800,
        'status' => 'imported',
        'hash' => md5('scope-import-line'),
        'is_cleared' => false,
    ]);

    $member = Member::create([
        'member_number' => 'MEM-SCOPE-02',
        'name' => 'Synthetic Only',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = app(FundPostingService::class)->submit($member, 500, now()->toDateString());
    app(FundPostingService::class)->accept($posting);
    $synthetic = $posting->fresh()->bankTransaction;

    $awaitingIds = $this->matching->applyBankLinesAwaitingPostingScope(BankTransaction::query())->pluck('id');

    expect($awaitingIds)->toContain($imported->id)
        ->and($awaitingIds)->not->toContain($synthetic->id)
        ->and($this->matching->bankLinesAwaitingPostingCount())->toBe(1);
});

test('master cash insights count posting and match queues separately from expense and fees', function () {
    $member = Member::create([
        'member_number' => 'MEM-SCOPE-03',
        'name' => 'Insights Member',
        'monthly_contribution_amount' => 0,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $statement = BankStatement::create([
        'filename' => 'insights-import.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Needs mirror',
        'amount' => 300,
        'status' => 'imported',
        'hash' => md5('insights-import-line'),
        'is_cleared' => false,
    ]);

    $posting = app(FundPostingService::class)->submit($member, 600, now()->toDateString());
    app(FundPostingService::class)->accept($posting);

    app(MasterExpenseDisbursementService::class)->disburse(Account::masterExpense(), 100, 'Insights expense');
    app(MasterFeeDisbursementService::class)->disburse(Account::masterFees(), 50, 'Insights fee');

    $cashSnapshot = app(AccountDetailInsightsService::class)->snapshot(Account::masterCash());
    $expenseSnapshot = app(AccountDetailInsightsService::class)->snapshot(Account::masterExpense());
    $feesSnapshot = app(AccountDetailInsightsService::class)->snapshot(Account::masterFees());

    $cashRows = collect($cashSnapshot['context']['panels'][0]['rows'])->pluck('value', 'label');

    expect($cashSnapshot['hero']['title'])->toBe(__('Bank lines awaiting posting'))
        ->and($cashRows[__('Bank to post')])->toBe('1')
        ->and($cashRows[__('Bank match')])->toBe('1')
        ->and($expenseSnapshot['hero']['title'])->toBe(__('Expense disbursements awaiting bank match'))
        ->and($feesSnapshot['hero']['title'])->toBe(__('Fee disbursements awaiting bank match'));
});
