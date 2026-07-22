<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Resources\BankAccounts\Pages\ListBankAccounts;
use App\Filament\Tenant\Widgets\BankClosedStatementLinesTableWidget;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\BankClearingMatchService;
use App\Services\BankClearingQueueService;
use App\Services\FundPostingService;
use App\Services\MasterExpenseDisbursementService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    Account::query()->delete();
    BankTransaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 50_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 50_000, 'is_master' => true]);
    Account::create(['type' => 'expense', 'name' => 'Master Expense', 'balance' => 5_000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Queue Workflow Admin',
        'email' => 'queue-workflow-'.uniqid('', true).'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->accounting = app(AccountingService::class);
});

test('unified work queue lists bank file and operational rows together', function () {
    app(AccountingService::class)->fundReserveAccountFromMasterFund(
        Account::masterExpense(),
        1_000,
        'Unified queue reserve',
    );

    app(MasterExpenseDisbursementService::class)->disburse(
        Account::masterExpense(),
        250,
        'Unified queue expense',
    );

    $statement = BankStatement::create([
        'filename' => 'unified-queue.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Unified queue import',
        'amount' => 250,
        'status' => 'imported',
        'hash' => md5('unified-queue-import'),
    ]);

    expect(app(BankClearingQueueService::class)->openCount())->toBe(2);

    Livewire::actingAs($this->admin, 'tenant')
        ->withQueryParams(['queueFilter' => 'all'])
        ->test(ListBankAccounts::class, [
            'activeTab' => 'queue',
            'queueFilter' => 'all',
        ])
        ->assertSuccessful()
        ->assertCanSeeTableRecords(
            app(BankClearingQueueService::class)->openItemsQuery()->get(),
        )
        ->assertSee('Unified queue import')
        ->assertSee(__('From bank file'))
        ->assertSee(__('Show balances & trends'));
});

test('navigation badge matches actionable open queue count', function () {
    $statement = BankStatement::create([
        'filename' => 'badge-queue.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Badge import',
        'amount' => 100,
        'status' => 'imported',
        'hash' => md5('badge-queue-import'),
    ]);

    expect(BankAccountsResource::getNavigationBadge())->toBe('1');
});

test('work queue can post a bank file row as member deposit', function () {
    $member = Member::factory()->create(['status' => 'active', 'monthly_contribution_amount' => 0]);
    $this->accounting->createMemberAccounts($member);

    $statement = BankStatement::create([
        'filename' => 'post-queue.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Queue post import',
        'amount' => 750,
        'status' => 'imported',
        'hash' => md5('queue-post-import'),
    ]);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class)
        ->callTableAction('postAs', $imported, [
            'type' => 'member_deposit',
            'member_id' => $member->id,
            'description' => 'Queue post import',
            'transaction_date' => $imported->transaction_date?->toDateString()
                ?? (string) $imported->transaction_date,
        ])
        ->assertNotified();

    expect($imported->fresh()->status)->toBe('posted')
        ->and($imported->fresh()->member_id)->toBe($member->id)
        ->and(app(BankClearingQueueService::class)->isBankFileItem($imported->fresh()))->toBeFalse();
});

test('work queue search does not query virtual queue columns', function () {
    app(AccountingService::class)->fundReserveAccountFromMasterFund(
        Account::masterExpense(),
        1_000,
        'Search queue reserve',
    );

    app(MasterExpenseDisbursementService::class)->disburse(
        Account::masterExpense(),
        250,
        'Office expense supplies',
    );

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class, [
            'channel' => 'bank',
            'activeTab' => 'queue',
            'queueFilter' => 'operations',
        ])
        ->searchTable('expens')
        ->assertSuccessful();
});

test('work queue can match operational row to bank line with evidence', function () {
    $member = Member::create([
        'member_number' => 'MEM-Q-MATCH',
        'name' => 'Queue Match Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    $this->accounting->createMemberAccounts($member);

    $posting = app(FundPostingService::class)->submit($member, 320, '2026-05-10');
    app(FundPostingService::class)->accept($posting);

    $operational = $posting->bankTransaction->fresh();

    $statement = BankStatement::create([
        'filename' => 'match-evidence.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2026-05-10',
        'description' => 'Match evidence import',
        'amount' => 320,
        'status' => 'imported',
        'hash' => md5('match-evidence-import'),
    ]);

    Livewire::actingAs($this->admin, 'tenant')
        ->withQueryParams(['queueFilter' => 'operations'])
        ->test(ListBankAccounts::class, [
            'channel' => 'bank',
            'activeTab' => 'queue',
            'queueFilter' => 'operations',
        ])
        ->callTableAction('matchToBankLine', $operational, data: [
            'imported_transaction_id' => $imported->id,
        ])
        ->assertNotified();

    expect($operational->fresh()->is_cleared)->toBeTrue()
        ->and($imported->fresh()->is_cleared)->toBeTrue()
        ->and(app(BankClearingQueueService::class)->openCount())->toBe(0);
});

test('work queue can clear operational row without bank evidence', function () {
    app(AccountingService::class)->fundReserveAccountFromMasterFund(
        Account::masterExpense(),
        1_000,
        'Clear evidence reserve',
    );

    app(MasterExpenseDisbursementService::class)->disburse(
        Account::masterExpense(),
        410,
        'Clear evidence expense',
    );

    $operational = BankTransaction::query()
        ->where('expense_disbursement_id', '!=', null)
        ->where('is_cleared', false)
        ->first();

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class, [
            'channel' => 'bank',
            'activeTab' => 'queue',
            'queueFilter' => 'operations',
        ])
        ->callTableAction('clearWithoutEvidence', $operational, data: [
            'note' => 'Confirmed with treasurer',
        ])
        ->assertNotified();

    $operational = $operational->fresh();

    expect($operational->is_cleared)->toBeTrue()
        ->and($operational->description)->toContain('Confirmed with treasurer')
        ->and(app(BankClearingMatchService::class)->pendingOperationalClearanceCount())->toBe(0);
});

test('work queue can bulk clear operational rows without bank evidence', function () {
    app(AccountingService::class)->fundReserveAccountFromMasterFund(
        Account::masterExpense(),
        1_000,
        'Bulk clear evidence reserve',
    );

    app(MasterExpenseDisbursementService::class)->disburse(
        Account::masterExpense(),
        420,
        'Bulk clear evidence expense',
    );

    $operational = BankTransaction::query()
        ->where('expense_disbursement_id', '!=', null)
        ->where('is_cleared', false)
        ->first();

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class, [
            'activeTab' => 'queue',
            'queueFilter' => 'operations',
        ])
        ->callTableBulkAction('clearWithoutEvidenceBulk', [$operational], data: [
            'note' => 'Treasurer sign-off',
        ])
        ->assertNotified();

    $operational = $operational->fresh();

    expect($operational->is_cleared)->toBeTrue()
        ->and($operational->description)->toContain('Treasurer sign-off')
        ->and(app(BankClearingMatchService::class)->pendingOperationalClearanceCount())->toBe(0);
});

test('import history tab shows batches and expandable closed lines together', function () {
    $statement = BankStatement::create([
        'filename' => 'history-combined.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Closed audit line',
        'amount' => 120,
        'status' => 'posted',
        'hash' => md5('history-combined-closed'),
        'is_cleared' => true,
    ]);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(ListBankAccounts::class)
        ->call('setBankTab', 'history')
        ->assertSuccessful()
        ->assertSee(__('Import batches'))
        ->assertSee('history-combined.csv')
        ->call('toggleClosedHistoryLines')
        ->assertSet('showClosedHistoryLines', true);

    Livewire::actingAs($this->admin, 'tenant')
        ->test(BankClosedStatementLinesTableWidget::class)
        ->assertSuccessful()
        ->assertCanSeeTableRecords(
            BankTransaction::query()->where('description', 'Closed audit line')->get(),
        );
});
