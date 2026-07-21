<?php

declare(strict_types=1);

use App\Filament\Tenant\Resources\BankAccounts\Pages\ViewBankStatement;
use App\Filament\Tenant\Resources\BankAccounts\RelationManagers\BankTransactionsRelationManager;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ManualBankStatementLineService;
use App\Services\SyntheticBankStatementFactory;
use App\Support\BusinessDay;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    Notification::fake();

    Account::query()->delete();
    Member::query()->delete();
    BankTransaction::query()->delete();

    Account::factory()->masterCash()->withBalance(50_000)->create();
    Account::factory()->masterFund()->withBalance(0)->create();
    Account::factory()->masterExpense()->withBalance(5_000)->create();
    Account::factory()->masterInvest()->withBalance(5_000)->create();
    Account::factory()->masterBank()->withBalance(0)->create();
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 5_000, 'is_master' => true]);

    $this->admin = User::create([
        'name' => 'Manual Bank Line Admin',
        'email' => 'manual-bank-line-'.uniqid('', true).'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->statement = BankStatement::create([
        'filename' => 'manual-lines-test.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 0,
        'imported_rows' => 0,
        'duplicate_rows' => 0,
    ]);

    $this->accounting = app(AccountingService::class);
});

test('manual service creates credit and debit imported lines on a real statement', function () {
    $credit = app(ManualBankStatementLineService::class)->create(
        $this->statement,
        ManualBankStatementLineService::KIND_CREDIT,
        9980,
        'Manual inbound transfer',
        '2025-11-05',
        'REF-CR-1',
        'transfer_in',
    );

    $debit = app(ManualBankStatementLineService::class)->create(
        $this->statement,
        ManualBankStatementLineService::KIND_DEBIT,
        2500,
        'Manual outbound transfer',
        BusinessDay::today()->toDateString(),
        null,
        'transfer_out',
    );

    expect($credit->amount)->toBe('9980.00')
        ->and($credit->status)->toBe('imported')
        ->and($credit->is_cleared)->toBeFalse()
        ->and($credit->transaction_type)->toBe(__('Transfer in'))
        ->and($debit->amount)->toBe('-2500.00')
        ->and($debit->status)->toBe('imported')
        ->and($this->statement->fresh()->imported_rows)->toBe(2);
});

test('manual service rejects membership import placeholder statements', function () {
    $placeholder = BankStatement::create([
        'filename' => 'membership-subscription-fees',
        'bank_name' => __('Membership subscription fees'),
        'status' => 'completed',
        'total_rows' => 0,
        'imported_rows' => 0,
        'duplicate_rows' => 0,
    ]);

    expect(fn () => app(ManualBankStatementLineService::class)->create(
        $placeholder,
        ManualBankStatementLineService::KIND_CREDIT,
        100,
        'Should fail',
        BusinessDay::today()->toDateString(),
    ))->toThrow(InvalidArgumentException::class);
});

test('manual service creates accepted member posting clearance line', function () {
    $member = Member::factory()->create(['status' => 'active']);
    $this->accounting->createMemberAccounts($member);

    $statement = app(SyntheticBankStatementFactory::class)->memberPostings();

    $line = app(ManualBankStatementLineService::class)->create(
        $statement,
        ManualBankStatementLineService::KIND_CREDIT,
        1500,
        'Manual deposit for match',
        '2025-11-05',
        'DEP-1',
        null,
        $member->id,
    );

    expect($line->bank_statement_id)->toBe($statement->id)
        ->and((float) $line->amount)->toBe(1500.0)
        ->and($line->fund_posting_id)->not->toBeNull()
        ->and($line->is_cleared)->toBeFalse()
        ->and($line->status)->toBe('posted')
        ->and($line->member_id)->toBe($member->id)
        ->and($line->fundPosting?->status)->toBe('accepted');
});

test('manual service creates accepted member cash-out clearance line', function () {
    $member = Member::factory()->create(['status' => 'active']);
    $this->accounting->createMemberAccounts($member);

    AccountingService::withoutMemberCashCollection(function () use ($member): void {
        $this->accounting->creditMemberCashWithMasterMirror(
            $member->cashAccount,
            2_000,
            'Seed cash',
            __('(seed mirror)'),
            null,
            null,
            $member->id,
        );
    });

    $statement = app(SyntheticBankStatementFactory::class)->memberCashOuts();

    $line = app(ManualBankStatementLineService::class)->create(
        $statement,
        ManualBankStatementLineService::KIND_DEBIT,
        750,
        'Manual cash-out for match',
        '2025-11-05',
        null,
        null,
        $member->id,
    );

    expect($line->bank_statement_id)->toBe($statement->id)
        ->and((float) $line->amount)->toBe(-750.0)
        ->and($line->cash_out_request_id)->not->toBeNull()
        ->and($line->is_cleared)->toBeFalse()
        ->and($line->member_id)->toBe($member->id)
        ->and((float) $member->fresh()->getCashBalance())->toBe(1250.0);
});

test('manual service creates master expense disbursement clearance line', function () {
    $statement = app(SyntheticBankStatementFactory::class)->masterExpenseDisbursements();

    $line = app(ManualBankStatementLineService::class)->create(
        $statement,
        ManualBankStatementLineService::KIND_DEBIT,
        400,
        'Manual expense payout',
        BusinessDay::today()->toDateString(),
    );

    expect($line->bank_statement_id)->toBe($statement->id)
        ->and((float) $line->amount)->toBe(-400.0)
        ->and($line->expense_disbursement_id)->not->toBeNull()
        ->and($line->is_cleared)->toBeFalse()
        ->and((float) Account::masterExpense()->fresh()->balance)->toBe(4_600.0);
});

test('bank transactions relation manager can add a manual credit line', function () {
    $component = Livewire::actingAs($this->admin, 'tenant')
        ->test(BankTransactionsRelationManager::class, [
            'ownerRecord' => $this->statement,
            'pageClass' => ViewBankStatement::class,
        ])
        ->assertSuccessful();

    $headerNames = collect($component->instance()->getTable()->getHeaderActions())
        ->map(fn ($action) => $action->getName())
        ->all();

    expect($headerNames)->toContain('addManualBankLine');

    $component
        ->callTableAction('addManualBankLine', data: [
            'kind' => ManualBankStatementLineService::KIND_CREDIT,
            'transaction_type' => 'deposit',
            'amount' => 1500,
            'transaction_date' => '2025-11-05',
            'description' => 'Manual deposit evidence',
            'reference' => 'MAN-1',
        ])
        ->assertNotified();

    $line = BankTransaction::query()->where('bank_statement_id', $this->statement->id)->first();

    expect($line)->not->toBeNull()
        ->and((float) $line->amount)->toBe(1500.0)
        ->and($line->description)->toBe('Manual deposit evidence')
        ->and($line->status)->toBe('imported');
});

test('bank transactions relation manager can add a member posting operational line', function () {
    $member = Member::factory()->create(['status' => 'active', 'name' => 'Ops Deposit Member']);
    $this->accounting->createMemberAccounts($member);

    $statement = app(SyntheticBankStatementFactory::class)->memberPostings();

    Livewire::actingAs($this->admin, 'tenant')
        ->test(BankTransactionsRelationManager::class, [
            'ownerRecord' => $statement,
            'pageClass' => ViewBankStatement::class,
        ])
        ->assertSuccessful()
        ->callTableAction('addManualBankLine', data: [
            'kind' => ManualBankStatementLineService::KIND_CREDIT,
            'amount' => 9980,
            'transaction_date' => '2025-11-05',
            'description' => 'Ops deposit line',
            'reference' => 'OPS-DEP',
            'member_id' => $member->id,
        ])
        ->assertNotified();

    $line = BankTransaction::query()
        ->where('bank_statement_id', $statement->id)
        ->whereNotNull('fund_posting_id')
        ->first();

    expect($line)->not->toBeNull()
        ->and((float) $line->amount)->toBe(9980.0)
        ->and($line->is_cleared)->toBeFalse();
});
