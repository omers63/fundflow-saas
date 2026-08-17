<?php

declare(strict_types=1);

use App\Filament\Support\MoneyDisplay;
use App\Filament\Tenant\Pages\JobsPage;
use App\Jobs\Tenant\BusinessDayWindowRollbackJob;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\ExpenseDisbursement;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\BankClearingMatchService;
use App\Services\BankClearingQueueService;
use App\Services\BusinessDayWindowRollbackService;
use App\Services\ContributionCollectionCycleService;
use App\Services\FundFlowService;
use App\Services\FundPostingService;
use App\Services\Loans\LoanDefaultService;
use App\Services\Loans\LoanEarlySettlementService;
use App\Services\Loans\LoanGuarantorTransferService;
use App\Services\Loans\LoanRepaymentService;
use App\Services\MasterAccountInvariantService;
use App\Services\MasterExpenseDisbursementService;
use App\Services\MemberCashOutService;
use App\Services\MemberFreezeService;
use App\Services\MemberFundOutService;
use App\Services\MembershipApplicationApprovalService;
use App\Services\MemberStatusService;
use App\Support\AutomationSchedulerGate;
use App\Support\BusinessDay;
use App\Support\BusinessDaySettings;
use App\Support\BusinessDayWindowRollbackEventInventory;
use App\Support\ContributionCollectionStatus;
use App\Support\LoanRepaymentNote;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Contribution::query()->delete();
    LoanInstallment::query()->delete();
    Loan::query()->delete();
    Member::query()->delete();
    MonthlyStatement::query()->forceDelete();
    ReconciliationSnapshot::query()->delete();
    ReconciliationException::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
    $this->rollback = app(BusinessDayWindowRollbackService::class);

    BusinessDaySettings::saveFromForm('2026-02-20');
    Carbon::setTestNow('2026-02-20 12:00:00');
});

afterEach(function () {
    BusinessDaySettings::saveFromForm(null);
    Carbon::setTestNow();
});

function rollbackWindowMember(AccountingService $accounting, float $cash, array $overrides = []): Member
{
    BusinessDaySettings::saveFromForm('2026-02-06');

    $member = Member::create(array_merge([
        'member_number' => 'RW-'.uniqid(),
        'name' => 'Rollback Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => Carbon::parse('2024-01-01'),
        'status' => 'active',
    ], $overrides));
    $accounting->createMemberAccounts($member);
    AccountingService::withoutMemberCashCollection(
        fn () => $accounting->credit($member->cashAccount, $cash, 'Seed cash'),
    );

    BusinessDaySettings::saveFromForm('2026-02-20');

    return $member->fresh();
}

function rollbackWindowMemberWithMirroredCash(AccountingService $accounting, float $cash, array $overrides = []): Member
{
    $member = rollbackWindowMember($accounting, $cash, $overrides);

    BusinessDaySettings::saveFromForm('2026-02-06');
    Carbon::setTestNow('2026-02-06 12:00:00');
    AccountingService::withoutMasterPoolMirror(
        fn () => $accounting->credit(Account::masterCash(), $cash, 'Seed master cash'),
    );
    BusinessDaySettings::saveFromForm('2026-02-20');
    Carbon::setTestNow('2026-02-20 12:00:00');

    return $member->fresh();
}

function rollbackImportedBankLine(array $overrides = []): BankTransaction
{
    $statement = BankStatement::firstOrCreate(
        ['filename' => 'rollback-window.csv'],
        [
            'bank_name' => 'Test Bank',
            'status' => 'completed',
            'total_rows' => 1,
            'imported_rows' => 1,
            'duplicate_rows' => 0,
        ],
    );

    return BankTransaction::create(array_merge([
        'bank_statement_id' => $statement->id,
        'transaction_date' => '2026-01-15',
        'description' => 'Imported rollback line',
        'amount' => 250,
        'status' => 'imported',
        'hash' => md5('rollback-import-'.uniqid()),
        'is_cleared' => false,
    ], $overrides));
}

test('rollback reverses a contribution collected after the as-of date', function () {
    $member = rollbackWindowMember($this->accounting, 800);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(2, 2026),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    expect(app(ContributionCollectionCycleService::class)->attemptCollection($contribution))->toBe('collected');

    $cashAfterCollect = (float) $member->fresh()->cashAccount->balance;

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    expect($report->blocked)->toBeFalse()
        ->and($report->contributions)->toBe(1)
        ->and($contribution->fresh()->status)->toBe('pending')
        ->and($contribution->fresh()->collection_status)->toBe(ContributionCollectionStatus::PENDING)
        ->and((float) $contribution->fresh()->amount_collected)->toBe(0.0)
        ->and((float) $member->fresh()->cashAccount->balance)->toBeGreaterThan($cashAfterCollect)
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(800.0);
});

test('rollback of a collected contribution keeps master cash and fund pool mirrors balanced', function () {
    $member = rollbackWindowMemberWithMirroredCash($this->accounting, 800);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(2, 2026),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    expect(app(ContributionCollectionCycleService::class)->attemptCollection($contribution))->toBe('collected');

    $this->rollback->execute(Carbon::parse('2026-02-06'));

    $pool = app(MasterAccountInvariantService::class)->check();

    expect($pool['balanced'])->toBeTrue()
        ->and($pool['cash_delta'])->toBe(0.0)
        ->and($pool['fund_delta'])->toBe(0.0)
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(800.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(800.0);
});

test('rollback preview does not mutate collected contributions', function () {
    $member = rollbackWindowMember($this->accounting, 800);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(2, 2026),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    app(ContributionCollectionCycleService::class)->attemptCollection($contribution);

    $preview = $this->rollback->preview(Carbon::parse('2026-02-06'));
    $contributionEvents = collect($preview->sections)->firstWhere('heading', __('Contributions'));

    expect($preview->dryRun)->toBeTrue()
        ->and($preview->contributions)->toBe(1)
        ->and($contribution->fresh()->status)->toBe('posted')
        ->and($contributionEvents['events'][0]['title'])->toBe($member->name)
        ->and($contributionEvents['events'][0]['meta'])->toContain(MoneyDisplay::amount(500));
});

test('rollback preview lists contribution, EMI, deposit, and cash-out details', function () {
    Notification::fake();

    $member = rollbackWindowMember($this->accounting, 2000);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(2, 2026),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    expect(app(ContributionCollectionCycleService::class)->attemptCollection($contribution))->toBe('collected');

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'member_portion' => 12_000,
        'master_portion' => 12_000,
        'settlement_threshold' => 0,
        'installments_count' => 12,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
        'first_repayment_month' => 2,
        'first_repayment_year' => 2026,
    ]);

    $installment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-02-15'),
        'status' => 'pending',
    ]);

    expect(app(LoanRepaymentService::class)->applyOpenPeriodRepaymentForMember($member->fresh()))->toBe('applied');

    $posting = app(FundPostingService::class)->submit($member->fresh(), 250, '2026-02-20');
    app(FundPostingService::class)->accept($posting);

    $cashOuts = app(MemberCashOutService::class);
    $cashOut = $cashOuts->submit($member->fresh(), 100);
    $cashOuts->accept($cashOut);

    $report = $this->rollback->preview(Carbon::parse('2026-02-06'));
    $byHeading = collect($report->sections)->keyBy('heading');

    expect($report->dryRun)->toBeTrue()
        ->and($contribution->fresh()->status)->toBe('posted')
        ->and($installment->fresh()->paid_at)->not->toBeNull()
        ->and($posting->fresh()->status)->toBe('accepted')
        ->and($cashOut->fresh()->status)->toBe('accepted')
        ->and($byHeading[__('Contributions')]['events'][0]['title'])->toBe($member->name)
        ->and($byHeading[__('Contributions')]['events'][0]['id'])->toBe(
            BusinessDayWindowRollbackEventInventory::modelKey('contributions', $contribution->fresh()),
        )
        ->and($byHeading[__('Contributions')]['events'][0]['meta'])->toContain(MoneyDisplay::amount(500))
        ->and($byHeading[__('EMIs')]['events'][0]['title'])->toBe($member->name)
        ->and($byHeading[__('EMIs')]['events'][0]['meta'])->toContain(__('EMI #:number', ['number' => 1]))
        ->and($byHeading[__('EMIs')]['events'][0]['meta'])->toContain(MoneyDisplay::amount(1000))
        ->and($byHeading[__('Deposits')]['events'][0]['title'])->toBe($member->name)
        ->and($byHeading[__('Deposits')]['events'][0]['meta'])->toContain(MoneyDisplay::amount(250))
        ->and($byHeading[__('Cash-outs')]['events'][0]['title'])->toBe($member->name)
        ->and($byHeading[__('Cash-outs')]['events'][0]['meta'])->toContain(MoneyDisplay::amount(100));
});

test('rollback unpays an EMI collected after the as-of date', function () {
    $member = rollbackWindowMember($this->accounting, 2000, ['monthly_contribution_amount' => 0]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'member_portion' => 12_000,
        'master_portion' => 12_000,
        'settlement_threshold' => 0,
        'installments_count' => 12,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
        'first_repayment_month' => 2,
        'first_repayment_year' => 2026,
    ]);

    $installment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-02-15'),
        'status' => 'pending',
    ]);

    expect(app(LoanRepaymentService::class)->applyOpenPeriodRepaymentForMember($member->fresh()))->toBe('applied');

    $this->rollback->execute(Carbon::parse('2026-02-06'));

    expect($installment->fresh()->status)->toBe('pending')
        ->and($installment->fresh()->paid_at)->toBeNull()
        ->and((float) $loan->fresh()->repaid_to_master)->toBe(0.0)
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(2000.0);
});

test('rollback of a collected EMI keeps master cash and fund pool mirrors balanced', function () {
    $member = rollbackWindowMemberWithMirroredCash($this->accounting, 2000, ['monthly_contribution_amount' => 0]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'member_portion' => 12_000,
        'master_portion' => 12_000,
        'settlement_threshold' => 0,
        'installments_count' => 12,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
        'first_repayment_month' => 2,
        'first_repayment_year' => 2026,
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-02-15'),
        'status' => 'pending',
    ]);

    expect(app(LoanRepaymentService::class)->applyOpenPeriodRepaymentForMember($member->fresh()))->toBe('applied');

    $this->rollback->execute(Carbon::parse('2026-02-06'));

    $pool = app(MasterAccountInvariantService::class)->check();

    expect($pool['balanced'])->toBeTrue()
        ->and($pool['cash_delta'])->toBe(0.0)
        ->and($pool['fund_delta'])->toBe(0.0)
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(2000.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(2000.0);
});

test('rollback reverses a deposit accepted in the window', function () {
    $member = rollbackWindowMember($this->accounting, 100, ['monthly_contribution_amount' => 0]);

    $posting = app(FundPostingService::class)->submit($member, 250, '2026-02-20');
    app(FundPostingService::class)->accept($posting);
    $operationalId = (int) $posting->fresh()->bank_transaction_id;

    expect((float) $member->fresh()->cashAccount->balance)->toBe(350.0)
        ->and($operationalId)->toBeGreaterThan(0)
        ->and(app(BankClearingQueueService::class)->openItemsQuery()->whereKey($operationalId)->exists())->toBeTrue();

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    expect($report->blocked)->toBeFalse()
        ->and($report->deposits)->toBe(1)
        ->and($report->manualJournals)->toBe(0)
        ->and($posting->fresh()->status)->toBe('pending')
        ->and($posting->fresh()->bank_transaction_id)->toBeNull()
        ->and(BankTransaction::query()->find($operationalId))->toBeNull()
        ->and(app(BankClearingQueueService::class)->openItemsQuery()->whereKey($operationalId)->exists())->toBeFalse()
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(100.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(0.0);
});

test('rollback unmatches a deposit cleared against an imported bank line', function () {
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $member = rollbackWindowMember($this->accounting, 100, ['monthly_contribution_amount' => 0]);
    $posting = app(FundPostingService::class)->submit($member, 250, '2026-02-20');
    app(FundPostingService::class)->accept($posting);

    $imported = rollbackImportedBankLine(['amount' => 250, 'description' => 'Matched deposit']);
    app(BankClearingMatchService::class)->clearMatchPair($posting->bankTransaction->fresh(), $imported);

    expect($imported->fresh()->is_cleared)->toBeTrue()
        ->and($imported->fresh()->master_bank_transaction_id)->not->toBeNull()
        ->and((float) Account::masterBank()->fresh()->balance)->toBe(250.0);

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    $imported = $imported->fresh();
    $operational = $posting->fresh()->bankTransaction;

    expect($report->bankMatches)->toBe(1)
        ->and($report->deposits)->toBe(1)
        ->and($posting->fresh()->status)->toBe('pending')
        ->and($posting->fresh()->bank_transaction_id)->toBeNull()
        ->and($imported->is_cleared)->toBeFalse()
        ->and($imported->status)->toBe('imported')
        ->and($imported->fund_posting_id)->toBeNull()
        ->and($imported->master_bank_transaction_id)->toBeNull()
        ->and($operational)->toBeNull()
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(100.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(0.0)
        ->and((float) Account::masterBank()->fresh()->balance)->toBe(0.0);
});

test('rollback unmatches a cash-out cleared against an imported bank line', function () {
    Notification::fake();
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $member = rollbackWindowMember($this->accounting, 1_000, ['monthly_contribution_amount' => 0]);
    $cashOuts = app(MemberCashOutService::class);
    $request = $cashOuts->submit($member, 250);
    $cashOuts->accept($request);

    $imported = rollbackImportedBankLine([
        'amount' => -250,
        'description' => 'Matched cash-out',
    ]);
    app(BankClearingMatchService::class)->clearMatchPair($request->fresh()->bankTransaction, $imported);

    expect($imported->fresh()->is_cleared)->toBeTrue()
        ->and((float) Account::masterBank()->fresh()->balance)->toBe(-250.0);

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    $imported = $imported->fresh();

    expect($report->bankMatches)->toBe(1)
        ->and($report->cashOuts)->toBe(1)
        ->and($request->fresh()->status)->toBe('pending')
        ->and($request->fresh()->bank_transaction_id)->toBeNull()
        ->and($imported->is_cleared)->toBeFalse()
        ->and($imported->status)->toBe('imported')
        ->and($imported->cash_out_request_id)->toBeNull()
        ->and($imported->master_bank_transaction_id)->toBeNull()
        ->and(BankTransaction::query()->find($imported->id))->not->toBeNull()
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(1_000.0)
        ->and((float) Account::masterBank()->fresh()->balance)->toBe(0.0);
});

test('undoing a matched deposit also unmatches the imported line', function () {
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $member = rollbackWindowMember($this->accounting, 100, ['monthly_contribution_amount' => 0]);
    $posting = app(FundPostingService::class)->submit($member, 250, '2026-02-20');
    app(FundPostingService::class)->accept($posting);

    $imported = rollbackImportedBankLine(['amount' => 250]);
    app(BankClearingMatchService::class)->clearMatchPair($posting->bankTransaction->fresh(), $imported);

    $report = $this->rollback->execute(
        Carbon::parse('2026-02-06'),
        [BusinessDayWindowRollbackEventInventory::modelKey('deposits', $posting->fresh())],
    );

    $imported = $imported->fresh();

    expect($report->deposits)->toBe(1)
        ->and($report->bankMatches)->toBe(0)
        ->and($posting->fresh()->status)->toBe('pending')
        ->and($posting->fresh()->bank_transaction_id)->toBeNull()
        ->and($imported->is_cleared)->toBeFalse()
        ->and($imported->fund_posting_id)->toBeNull()
        ->and($imported->master_bank_transaction_id)->toBeNull()
        ->and((float) Account::masterBank()->fresh()->balance)->toBe(0.0);
});

test('rollback reverses a bank-file posting whose statement date is on or before as-of', function () {
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $member = rollbackWindowMember($this->accounting, 0, ['monthly_contribution_amount' => 0]);
    $imported = rollbackImportedBankLine([
        'amount' => 400,
        'description' => 'CSV deposit',
        'transaction_date' => '2026-01-15',
    ]);

    AccountingService::withoutMemberCashCollection(
        fn () => app(FundFlowService::class)->ensureMirroredAndPostToMember($imported, $member),
    );

    expect($imported->fresh()->status)->toBe('posted')
        ->and($imported->fresh()->is_cleared)->toBeTrue()
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(400.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(400.0)
        ->and((float) Account::masterBank()->fresh()->balance)->toBe(400.0);

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    $imported = $imported->fresh();

    expect($report->bankMatches)->toBe(1)
        ->and($imported->status)->toBe('imported')
        ->and($imported->is_cleared)->toBeFalse()
        ->and($imported->member_id)->toBeNull()
        ->and($imported->master_cash_transaction_id)->toBeNull()
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(0.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(0.0)
        ->and((float) Account::masterBank()->fresh()->balance)->toBe(0.0);
});

test('rollback reverses a bank-file posting even when cleared_at is the statement date', function () {
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $member = rollbackWindowMember($this->accounting, 0, ['monthly_contribution_amount' => 0]);
    $imported = rollbackImportedBankLine([
        'amount' => 400,
        'description' => 'Legacy CSV deposit',
        'transaction_date' => '2026-01-15',
    ]);

    AccountingService::withoutMemberCashCollection(
        fn () => app(FundFlowService::class)->ensureMirroredAndPostToMember($imported, $member),
    );

    $imported->update(['cleared_at' => '2026-01-15']);

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    $imported = $imported->fresh();

    expect($report->bankMatches)->toBe(1)
        ->and($imported->status)->toBe('imported')
        ->and($imported->is_cleared)->toBeFalse()
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(0.0)
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(0.0)
        ->and((float) Account::masterBank()->fresh()->balance)->toBe(0.0);
});

test('rollback ignores an uncleared bank-file line dated after as-of', function () {
    $imported = rollbackImportedBankLine([
        'amount' => 180,
        'description' => 'Open Nov-window CSV line',
        'transaction_date' => '2026-02-20',
    ]);

    expect(app(BankClearingQueueService::class)->openItemsQuery()->whereKey($imported->id)->exists())->toBeTrue();

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    $imported = $imported->fresh();

    expect($report->bankMatches)->toBe(1)
        ->and($imported->status)->toBe('ignored')
        ->and($imported->is_cleared)->toBeFalse()
        ->and(app(BankClearingQueueService::class)->openItemsQuery()->whereKey($imported->id)->exists())->toBeFalse();
});

test('rollback ignores a reversed bank-file posting dated after as-of', function () {
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);

    $member = rollbackWindowMember($this->accounting, 0, ['monthly_contribution_amount' => 0]);
    $imported = rollbackImportedBankLine([
        'amount' => 400,
        'description' => 'CSV deposit after as-of',
        'transaction_date' => '2026-02-20',
    ]);

    AccountingService::withoutMemberCashCollection(
        fn () => app(FundFlowService::class)->ensureMirroredAndPostToMember($imported, $member),
    );

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    $imported = $imported->fresh();

    expect($report->bankMatches)->toBe(1)
        ->and($imported->status)->toBe('ignored')
        ->and($imported->is_cleared)->toBeFalse()
        ->and($imported->master_cash_transaction_id)->toBeNull()
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(0.0)
        ->and(app(BankClearingQueueService::class)->openItemsQuery()->whereKey($imported->id)->exists())->toBeFalse();
});

test('rollback undoes a clear-without-evidence after the as-of date', function () {
    $member = rollbackWindowMember($this->accounting, 100, ['monthly_contribution_amount' => 0]);

    BusinessDaySettings::saveFromForm('2026-02-06');
    $posting = app(FundPostingService::class)->submit($member, 250, '2026-02-06');
    app(FundPostingService::class)->accept($posting);
    BusinessDaySettings::saveFromForm('2026-02-20');

    $operational = $posting->fresh()->bankTransaction;
    app(BankClearingMatchService::class)->clearWithoutEvidence($operational->fresh(), 'Verbal confirmation');

    expect($operational->fresh()->is_cleared)->toBeTrue();

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    expect($report->bankMatches)->toBe(1)
        ->and($report->deposits)->toBe(0)
        ->and($posting->fresh()->status)->toBe('accepted')
        ->and($operational->fresh()->is_cleared)->toBeFalse()
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(350.0);
});

test('rollback resumes a scheduler left paused by a previous window undo', function () {
    $member = rollbackWindowMember($this->accounting, 800);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(2, 2026),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    expect(app(ContributionCollectionCycleService::class)->attemptCollection($contribution))->toBe('collected');

    $scheduler = app(AutomationSchedulerGate::class);
    $scheduler->pause(__('Business day window rollback'));

    $this->rollback->execute(Carbon::parse('2026-02-06'));

    expect($scheduler->isPaused())->toBeFalse()
        ->and($contribution->fresh()->status)->toBe('pending');
});

test('rollback discards reconciliation snapshots and exceptions raised after as-of', function () {
    $member = rollbackWindowMember($this->accounting, 800);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(2, 2026),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    expect(app(ContributionCollectionCycleService::class)->attemptCollection($contribution))->toBe('collected');

    $keepSnapshot = ReconciliationSnapshot::create([
        'mode' => ReconciliationSnapshot::MODE_DAILY,
        'as_of' => Carbon::parse('2026-02-06 12:00:00'),
        'is_passing' => true,
        'critical_issues' => 0,
        'warnings' => 0,
        'summary' => [],
        'report' => ['verdict' => ['pass' => true]],
    ]);
    $windowSnapshot = ReconciliationSnapshot::create([
        'mode' => ReconciliationSnapshot::MODE_REALTIME,
        'as_of' => Carbon::parse('2026-02-20 12:00:00'),
        'is_passing' => false,
        'critical_issues' => 1,
        'warnings' => 0,
        'summary' => [],
        'report' => ['verdict' => ['pass' => false]],
    ]);
    $keepException = ReconciliationException::create([
        'exception_code' => 'KEEP_BEFORE_AS_OF',
        'domain' => 'ledger',
        'severity' => 'low',
        'status' => ReconciliationException::STATUS_RESOLVED,
        'raised_at' => Carbon::parse('2026-02-06 12:00:00'),
        'resolved_at' => Carbon::parse('2026-02-06 12:00:00'),
        'affected_entities' => [],
    ]);
    $openKeepException = ReconciliationException::create([
        'exception_code' => 'OPEN_KEEP_DAY',
        'domain' => 'ledger',
        'severity' => 'medium',
        'status' => ReconciliationException::STATUS_OPEN,
        'raised_at' => Carbon::parse('2026-02-06 12:00:00'),
        'affected_entities' => [],
    ]);
    $windowException = ReconciliationException::create([
        'exception_code' => 'WINDOW_AFTER_AS_OF',
        'domain' => 'bank_clearing',
        'severity' => 'high',
        'status' => ReconciliationException::STATUS_OPEN,
        'raised_at' => Carbon::parse('2026-02-20 12:00:00'),
        'affected_entities' => [],
    ]);

    $this->rollback->execute(Carbon::parse('2026-02-06'));

    $cutoff = Carbon::parse('2026-02-06')->endOfDay();

    expect(ReconciliationSnapshot::query()->whereKey($keepSnapshot->id)->exists())->toBeTrue()
        ->and(ReconciliationSnapshot::query()->whereKey($windowSnapshot->id)->exists())->toBeFalse()
        ->and(ReconciliationException::query()->whereKey($keepException->id)->exists())->toBeTrue()
        ->and(ReconciliationException::query()->whereKey($openKeepException->id)->exists())->toBeFalse()
        ->and(ReconciliationException::query()->whereKey($windowException->id)->exists())->toBeFalse()
        ->and(ReconciliationSnapshot::query()->where('as_of', '>', $cutoff)->exists())->toBeFalse()
        ->and(ReconciliationException::query()->open()->exists())->toBeFalse();
});

test('rollback reverses a cash-out accepted in the window', function () {
    Notification::fake();

    $member = rollbackWindowMember($this->accounting, 1_000, ['monthly_contribution_amount' => 0]);

    $cashOuts = app(MemberCashOutService::class);
    $request = $cashOuts->submit($member, 250);
    $cashOuts->accept($request);

    expect((float) $member->fresh()->cashAccount->balance)->toBe(750.0);

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    expect($report->cashOuts)->toBe(1)
        ->and($request->fresh()->status)->toBe('pending')
        ->and($request->fresh()->bank_transaction_id)->toBeNull()
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(1_000.0);
});

test('rollback reverses an expense disbursement from the window', function () {
    BusinessDaySettings::saveFromForm('2026-02-06');
    Account::create(['type' => 'expense', 'name' => 'Master Expense', 'balance' => 0, 'is_master' => true]);
    $this->accounting->credit(Account::masterFund(), 1_000, 'Seed fund');
    $this->accounting->fundReserveAccountFromMasterFund(Account::masterExpense(), 1_000, 'Reserve');
    BusinessDaySettings::saveFromForm('2026-02-20');

    $disbursement = app(MasterExpenseDisbursementService::class)->disburse(
        Account::masterExpense(),
        250,
        'Vendor',
    );

    expect((float) Account::masterExpense()->fresh()->balance)->toBe(750.0);

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    expect($report->disbursements)->toBe(1)
        ->and(ExpenseDisbursement::query()->whereKey($disbursement->id)->exists())->toBeFalse()
        ->and((float) Account::masterExpense()->fresh()->balance)->toBe(1_000.0);
});

test('rollback reverses a manual journal from the window', function () {
    $member = rollbackWindowMember($this->accounting, 0, ['monthly_contribution_amount' => 0]);

    $this->accounting->postManualCredit($member->cashAccount, 75, 'Manual top-up');

    expect((float) $member->fresh()->cashAccount->balance)->toBe(75.0);

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    expect($report->manualJournals)->toBe(1)
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(0.0)
        ->and(Transaction::query()->whereNull('reference_type')->where('description', 'Manual top-up')->exists())->toBeTrue();
});

test('rollback reverses a membership application posted in the window', function () {
    Notification::fake();

    $application = MembershipApplication::create([
        'name' => 'Jane Applicant',
        'email' => 'jane-rollback-'.uniqid().'@example.com',
        'password' => 'SecurePass1!',
        'phone' => '+966501234567',
        'application_type' => 'new',
        'national_id' => '1234567890',
        'date_of_birth' => '1990-01-15',
        'address' => '123 Main Street',
        'city' => 'Riyadh',
        'mobile_phone' => '+966501234567',
        'bank_account_number' => '1234567890123456',
        'iban' => 'SA0380000000608010167519',
        'membership_fee_amount' => 75,
        'membership_fee_required_amount' => 50,
        'membership_fee_transfer_date' => '2026-02-20',
        'membership_fee_transfer_reference' => 'TXN-RW-001',
        'status' => 'pending',
    ]);

    $member = app(MembershipApplicationApprovalService::class)->approve($application);

    expect($application->fresh()->status)->toBe('approved');

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    $openApplicationLedger = Transaction::query()
        ->where('reference_type', MembershipApplication::class)
        ->where('reference_id', $application->id)
        ->get()
        ->contains(fn (Transaction $transaction): bool => ! app(AccountingService::class)->isReversalEntry($transaction)
            && ! app(AccountingService::class)->hasExistingReversal($transaction));

    expect($report->blocked)->toBeFalse()
        ->and($report->applications)->toBe(1)
        ->and($application->fresh()->status)->toBe('pending')
        ->and($openApplicationLedger)->toBeFalse()
        ->and(Member::query()->whereKey($member->id)->exists())->toBeTrue();
});

test('rollback reverses a fund-out accepted in the window', function () {
    Notification::fake();

    $member = rollbackWindowMember($this->accounting, 0, ['monthly_contribution_amount' => 0]);
    BusinessDaySettings::saveFromForm('2026-02-06');
    $this->accounting->creditMemberFundWithMasterMirror(
        $member->fresh()->fundAccount,
        1_000,
        'Seed fund',
        __('(seed mirror)'),
        null,
        null,
        $member->id,
    );
    BusinessDaySettings::saveFromForm('2026-02-20');

    $fundOuts = app(MemberFundOutService::class);
    $request = $fundOuts->submit($member->fresh(), 250);
    $fundOuts->accept($request);

    expect((float) $member->fresh()->fundAccount->balance)->toBe(750.0);

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    expect($report->otherSources)->toBe(1)
        ->and($request->fresh()->status)->toBe('pending')
        ->and((float) $member->fresh()->fundAccount->balance)->toBe(1_000.0)
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(0.0);
});

test('rollback restores a loan early-settled in the window', function () {
    Notification::fake();

    $member = rollbackWindowMember($this->accounting, 5_000, ['monthly_contribution_amount' => 0]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 2_000,
        'amount_requested' => 2_000,
        'amount_approved' => 2_000,
        'amount_disbursed' => 2_000,
        'member_portion' => 2_000,
        'master_portion' => 2_000,
        'settlement_threshold' => 0,
        'installments_count' => 2,
        'interest_rate' => 0,
        'term_months' => 2,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
        'first_repayment_month' => 2,
        'first_repayment_year' => 2026,
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-02-15'),
        'status' => 'pending',
    ]);
    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-03-15'),
        'status' => 'pending',
    ]);

    app(LoanEarlySettlementService::class)->earlySettle(
        $loan->fresh(['member', 'installments']),
        sendNotification: false,
    );

    expect($loan->fresh()->status)->toBe('early_settled')
        ->and($loan->fresh()->settled_at)->not->toBeNull()
        ->and($loan->repayments()->where('notes', LoanRepaymentNote::fullEarlySettlement())->exists())->toBeTrue();

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    expect($report->blocked)->toBeFalse()
        ->and($report->earlySettlements)->toBe(1)
        ->and($loan->fresh()->status)->toBe('active')
        ->and($loan->fresh()->settled_at)->toBeNull()
        ->and($loan->installments()->whereIn('status', ['pending', 'overdue'])->count())->toBe(2)
        ->and($loan->repayments()->get()->contains(fn ($row) => LoanRepaymentNote::isSettlement($row->notes)))->toBeFalse()
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(5_000.0);
});

test('rollback restores a member withdrawn in the window', function () {
    Notification::fake();

    $member = rollbackWindowMember($this->accounting, 1_000, ['monthly_contribution_amount' => 0]);
    BusinessDaySettings::saveFromForm('2026-02-06');
    $this->accounting->creditMemberFundWithMasterMirror(
        $member->fresh()->fundAccount,
        500,
        'Seed fund',
        __('(seed mirror)'),
        null,
        null,
        $member->id,
    );
    BusinessDaySettings::saveFromForm('2026-02-20');

    app(MemberStatusService::class)->withdraw($member->fresh(), 'Moving away');

    expect($member->fresh()->status)->toBe('withdrawn')
        ->and($member->fresh()->getCashBalance())->toBe(0.0)
        ->and($member->fresh()->getFundBalance())->toBe(0.0);

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    $cashOut = CashOutRequest::query()->where('member_id', $member->id)->first();

    expect($report->blocked)->toBeFalse()
        ->and($report->withdrawals)->toBe(1)
        ->and($member->fresh()->status)->toBe('active')
        ->and($member->fresh()->contribution_cycles_active)->toBeTrue()
        ->and($member->fresh()->payout_frozen_at)->toBeNull()
        ->and($member->fresh()->last_withdrawn_at)->toBeNull()
        ->and($cashOut?->status)->toBe('pending')
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(1_000.0)
        ->and((float) $member->fresh()->fundAccount->balance)->toBe(500.0);
});

test('rollback restores a held-payout withdrawal in the window', function () {
    Notification::fake();

    $member = rollbackWindowMember($this->accounting, 800, ['monthly_contribution_amount' => 0]);
    BusinessDaySettings::saveFromForm('2026-02-06');
    $this->accounting->creditMemberFundWithMasterMirror(
        $member->fresh()->fundAccount,
        200,
        'Seed fund',
        __('(seed mirror)'),
        null,
        null,
        $member->id,
    );
    BusinessDaySettings::saveFromForm('2026-02-20');

    app(MemberStatusService::class)->withdraw($member->fresh(), 'Hold review', holdPayout: true);

    expect($member->fresh()->status)->toBe('withdrawn')
        ->and($member->fresh()->payout_frozen_at)->not->toBeNull()
        ->and($member->fresh()->getCashBalance())->toBe(800.0)
        ->and($member->fresh()->getFundBalance())->toBe(200.0);

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    expect($report->blocked)->toBeFalse()
        ->and($report->withdrawals)->toBe(1)
        ->and($member->fresh()->status)->toBe('active')
        ->and($member->fresh()->payout_frozen_at)->toBeNull()
        ->and($member->fresh()->last_withdrawn_at)->toBeNull()
        ->and(CashOutRequest::query()->where('member_id', $member->id)->exists())->toBeFalse()
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(800.0)
        ->and((float) $member->fresh()->fundAccount->balance)->toBe(200.0);
});

test('rollback restores a membership freeze applied in the window', function () {
    Notification::fake();

    $member = rollbackWindowMember($this->accounting, 0, ['monthly_contribution_amount' => 0]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'member_portion' => 12_000,
        'master_portion' => 12_000,
        'settlement_threshold' => 0,
        'installments_count' => 12,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
        'first_repayment_month' => 2,
        'first_repayment_year' => 2026,
    ]);

    $installment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-02-15'),
        'status' => 'pending',
    ]);

    $originalDue = $installment->due_date->toDateString();

    app(MemberFreezeService::class)->applyFreeze($member->fresh(), [
        'cycles' => 2,
        'reason' => 'Travel',
    ]);

    $pushedDue = $installment->fresh()->due_date->toDateString();

    expect($member->fresh()->status)->toBe('inactive')
        ->and($member->fresh()->frozen_at)->not->toBeNull()
        ->and((int) $member->fresh()->freeze_emi_cycles_pushed)->toBe(1)
        ->and($pushedDue)->not->toBe($originalDue);

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    expect($report->blocked)->toBeFalse()
        ->and($report->freezes)->toBe(1)
        ->and($member->fresh()->status)->toBe('active')
        ->and($member->fresh()->frozen_at)->toBeNull()
        ->and($member->fresh()->freeze_emi_cycles_pushed)->toBeNull()
        ->and($member->fresh()->contribution_cycles_active)->toBeTrue()
        ->and($installment->fresh()->due_date->toDateString())->not->toBe($pushedDue)
        ->and($installment->fresh()->due_date->lt(Carbon::parse($pushedDue)))->toBeTrue();
});

test('rollback undoes a freeze plan tick when a future cycle opened', function () {
    Notification::fake();

    $member = rollbackWindowMember($this->accounting, 0, ['monthly_contribution_amount' => 0]);
    rollbackWindowMember($this->accounting, 0);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'member_portion' => 12_000,
        'master_portion' => 12_000,
        'settlement_threshold' => 0,
        'installments_count' => 12,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
        'first_repayment_month' => 2,
        'first_repayment_year' => 2026,
    ]);

    $installment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-02-15'),
        'status' => 'pending',
    ]);

    BusinessDaySettings::saveFromForm('2026-02-06');
    app(MemberFreezeService::class)->applyFreeze($member->fresh(), [
        'cycles' => 2,
        'reason' => 'Travel',
    ]);
    BusinessDaySettings::saveFromForm('2026-02-20');

    $dueAfterFreeze = $installment->fresh()->due_date->toDateString();

    expect($member->fresh()->status)->toBe('inactive')
        ->and((int) $member->fresh()->freeze_emi_cycles_pushed)->toBe(1)
        ->and((int) $member->fresh()->freeze_cycles_remaining)->toBe(1);

    app(ContributionCollectionCycleService::class)->initializeOpenPeriod(3, 2026);

    expect((int) $member->fresh()->freeze_emi_cycles_pushed)->toBe(2)
        ->and((int) $member->fresh()->freeze_cycles_remaining)->toBe(0)
        ->and($member->fresh()->freeze_plan_ended_at)->not->toBeNull()
        ->and($installment->fresh()->due_date->toDateString())->not->toBe($dueAfterFreeze);

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    expect($report->blocked)->toBeFalse()
        ->and($report->freezeTicks)->toBe(1)
        ->and($report->freezes)->toBe(0)
        ->and($member->fresh()->status)->toBe('inactive')
        ->and($member->fresh()->frozen_at)->not->toBeNull()
        ->and((int) $member->fresh()->freeze_emi_cycles_pushed)->toBe(1)
        ->and((int) $member->fresh()->freeze_cycles_remaining)->toBe(1)
        ->and($member->fresh()->freeze_plan_ended_at)->toBeNull()
        ->and($installment->fresh()->due_date->toDateString())->toBe($dueAfterFreeze)
        ->and(Contribution::query()->whereDate('period', '2026-03-01')->exists())->toBeFalse();
});

test('rollback undoes leftover freeze ticks after future cycles were already deleted', function () {
    Notification::fake();

    $member = rollbackWindowMember($this->accounting, 0, ['monthly_contribution_amount' => 0]);
    rollbackWindowMember($this->accounting, 0);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'member_portion' => 12_000,
        'master_portion' => 12_000,
        'settlement_threshold' => 0,
        'installments_count' => 12,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => Carbon::parse('2026-01-01'),
        'disbursed_at' => Carbon::parse('2026-01-01'),
        'first_repayment_month' => 2,
        'first_repayment_year' => 2026,
    ]);

    $installment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-02-15'),
        'status' => 'pending',
    ]);

    BusinessDaySettings::saveFromForm('2026-02-06');
    app(MemberFreezeService::class)->applyFreeze($member->fresh(), [
        'cycles' => 4,
        'reason' => 'Travel',
    ]);
    BusinessDaySettings::saveFromForm('2026-02-20');

    $dueAfterFreeze = $installment->fresh()->due_date->toDateString();

    app(ContributionCollectionCycleService::class)->initializeOpenPeriod(3, 2026);
    app(ContributionCollectionCycleService::class)->initializeOpenPeriod(4, 2026);
    app(ContributionCollectionCycleService::class)->initializeOpenPeriod(5, 2026);

    expect((int) $member->fresh()->freeze_emi_cycles_pushed)->toBe(4)
        ->and((int) $member->fresh()->freeze_cycles_remaining)->toBe(0)
        ->and($member->fresh()->freeze_plan_ended_at)->not->toBeNull();

    Contribution::query()->whereDate('period', '>=', '2026-03-01')->delete();

    $report = $this->rollback->execute(Carbon::parse('2026-02-06'));

    expect($report->blocked)->toBeFalse()
        ->and($report->freezeTicks)->toBe(3)
        ->and($report->freezes)->toBe(0)
        ->and($member->fresh()->status)->toBe('inactive')
        ->and((int) $member->fresh()->freeze_emi_cycles_pushed)->toBe(1)
        ->and((int) $member->fresh()->freeze_cycles_remaining)->toBe(3)
        ->and($member->fresh()->freeze_plan_ended_at)->toBeNull()
        ->and($installment->fresh()->due_date->toDateString())->toBe($dueAfterFreeze);
});

test('rollback restores a guarantor transfer from the window', function () {
    $borrower = rollbackWindowMember($this->accounting, 0, ['name' => 'Borrower']);
    $guarantor = rollbackWindowMember($this->accounting, 0, ['name' => 'Guarantor', 'monthly_contribution_amount' => 500]);

    $tier = LoanTier::create([
        'tier_number' => max(1, (int) LoanTier::withTrashed()->max('tier_number') + 1),
        'label' => 'Rollback tier',
        'min_amount' => 1000,
        'max_amount' => 50_000,
        'min_monthly_installment' => 1000,
        'is_active' => true,
    ]);

    $loan = Loan::create([
        'member_id' => $borrower->id,
        'guarantor_member_id' => $guarantor->id,
        'loan_tier_id' => $tier->id,
        'amount' => 20_000,
        'amount_requested' => 20_000,
        'amount_approved' => 20_000,
        'amount_disbursed' => 20_000,
        'member_portion' => 10_000,
        'master_portion' => 10_000,
        'settlement_threshold' => 0.05,
        'repaid_to_master' => 2_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'installments_count' => 11,
        'status' => 'active',
        'applied_at' => Carbon::parse('2025-08-01'),
        'disbursed_at' => Carbon::parse('2025-08-01'),
        'first_repayment_month' => 9,
        'first_repayment_year' => 2025,
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => Carbon::parse('2025-09-05'),
        'status' => 'paid',
        'paid_at' => Carbon::parse('2025-09-05'),
        'amount_collected' => 1000,
    ]);
    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 2,
        'amount' => 1000,
        'due_date' => Carbon::parse('2025-10-05'),
        'status' => 'overdue',
    ]);
    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 3,
        'amount' => 1000,
        'due_date' => Carbon::parse('2026-03-05'),
        'status' => 'pending',
    ]);

    app(LoanGuarantorTransferService::class)->transferToGuarantor($loan->fresh());

    expect($loan->fresh()->member_id)->toBe($guarantor->id);

    $this->rollback->execute(Carbon::parse('2026-02-06'));

    $loan = $loan->fresh();

    expect($loan->status)->toBe('active')
        ->and($loan->member_id)->toBe($borrower->id)
        ->and($loan->transferred_to_guarantor_at)->toBeNull()
        ->and($borrower->fresh()->status)->toBe('active')
        ->and($loan->installments()->where('status', 'paid')->count())->toBe(1)
        ->and($loan->installments()->whereIn('status', ['pending', 'overdue'])->count())->toBeGreaterThan(1);
});

test('rollback unpays a guarantor debit from the window', function () {
    Notification::fake();
    Setting::set('loans', 'default_grace_cycles', 0);

    $borrower = rollbackWindowMember($this->accounting, 1000, ['monthly_contribution_amount' => 0]);
    $guarantor = rollbackWindowMember($this->accounting, 0, ['monthly_contribution_amount' => 0]);
    BusinessDaySettings::saveFromForm('2026-02-06');
    AccountingService::withoutMemberCashCollection(
        fn () => $this->accounting->credit($guarantor->fresh()->fundAccount, 50_000, 'Guarantor fund'),
    );
    BusinessDaySettings::saveFromForm('2026-02-20');

    $user = User::query()->create([
        'name' => 'Guarantor user',
        'email' => 'gua-rw-'.uniqid().'@example.test',
        'password' => Hash::make('password'),
        'is_admin' => false,
        'email_verified_at' => now(),
    ]);
    $guarantor->update(['user_id' => $user->id]);
    $borrower->update(['user_id' => $user->id]);

    $loan = Loan::create([
        'member_id' => $borrower->id,
        'guarantor_member_id' => $guarantor->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'member_portion' => 0,
        'master_portion' => 12_000,
        'settlement_threshold' => 0,
        'installments_count' => 6,
        'interest_rate' => 0,
        'term_months' => 6,
        'monthly_repayment' => 2400,
        'total_repaid' => 0,
        'status' => 'active',
        'late_repayment_count' => 2,
        'applied_at' => Carbon::parse('2025-11-01'),
        'disbursed_at' => Carbon::parse('2025-11-01'),
    ]);

    $installment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 2400,
        'due_date' => Carbon::parse('2025-11-05'),
        'status' => 'overdue',
        'paid_by_guarantor' => false,
    ]);

    $result = app(LoanDefaultService::class)->processDefaults();

    expect($result['debited_from_guarantor'])->toBe(1)
        ->and($installment->fresh()->status)->toBe('paid')
        ->and($installment->fresh()->paid_by_guarantor)->toBeTrue();

    $this->rollback->execute(Carbon::parse('2026-02-06'));

    expect($installment->fresh()->status)->toBe('overdue')
        ->and($installment->fresh()->paid_by_guarantor)->toBeFalse()
        ->and($loan->repayments()->where('notes', LoanRepaymentNote::installment(1, true))->exists())->toBeFalse();
});

test('rollback deletes statements generated after the as-of date', function () {
    $member = rollbackWindowMember($this->accounting, 0);

    MonthlyStatement::query()->create([
        'member_id' => $member->id,
        'period' => '2026-02',
        'opening_balance' => 0,
        'total_contributions' => 0,
        'total_repayments' => 0,
        'closing_balance' => 0,
        'generated_at' => Carbon::parse('2026-02-20 12:00:00'),
    ]);

    $this->rollback->execute(Carbon::parse('2026-02-06'));

    expect(MonthlyStatement::query()->count())->toBe(0);
});

test('second rollback is a no-op after a successful undo', function () {
    $member = rollbackWindowMember($this->accounting, 800);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(2, 2026),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    app(ContributionCollectionCycleService::class)->attemptCollection($contribution);
    $this->rollback->execute(Carbon::parse('2026-02-06'));

    $second = $this->rollback->execute(Carbon::parse('2026-02-06'));

    expect($second->contributions)->toBe(0)
        ->and($second->installments)->toBe(0)
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(800.0);
});

test('rollback undoes only selected events', function () {
    Notification::fake();

    $member = rollbackWindowMember($this->accounting, 2000);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(2, 2026),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    expect(app(ContributionCollectionCycleService::class)->attemptCollection($contribution))->toBe('collected');

    $cashOuts = app(MemberCashOutService::class);
    $cashOut = $cashOuts->submit($member->fresh(), 250);
    $cashOuts->accept($cashOut);

    $report = $this->rollback->execute(
        Carbon::parse('2026-02-06'),
        [BusinessDayWindowRollbackEventInventory::modelKey('contributions', $contribution->fresh())],
    );

    expect($report->contributions)->toBe(1)
        ->and($report->cashOuts)->toBe(0)
        ->and($contribution->fresh()->status)->toBe('pending')
        ->and($cashOut->fresh()->status)->toBe('accepted');
});

test('jobs page exposes the window rollback header action', function () {
    Filament::setCurrentPanel('tenant');

    $this->actingAs(User::create([
        'name' => 'Rollback Admin',
        'email' => 'rollback-admin-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');

    $component = Livewire::test(JobsPage::class)
        ->assertActionVisible('rollback_business_day_window');

    foreach ($component->instance()->getCachedHeaderActions() as $action) {
        expect($action->isIconButton())->toBeTrue()
            ->and($action->getTooltip())->not->toBeEmpty();
    }
});

test('rollback modal fills the as-of date when mounted', function () {
    Filament::setCurrentPanel('tenant');

    $this->actingAs(User::create([
        'name' => 'Rollback As-of Admin',
        'email' => 'rollback-asof-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');

    Livewire::test(JobsPage::class)
        ->mountAction('rollback_business_day_window')
        ->assertActionDataSet([
            'as_of' => BusinessDay::today()->toDateString(),
        ]);
});

test('rollback modal lists selectable events below the as-of date', function () {
    Notification::fake();
    Filament::setCurrentPanel('tenant');

    $member = rollbackWindowMember($this->accounting, 800);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(2, 2026),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    expect(app(ContributionCollectionCycleService::class)->attemptCollection($contribution))->toBe('collected');

    $cashOuts = app(MemberCashOutService::class);
    $cashOut = $cashOuts->submit($member->fresh(), 250);
    $cashOuts->accept($cashOut);

    $this->actingAs(User::create([
        'name' => 'Rollback Preview Admin',
        'email' => 'rollback-preview-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');

    $component = Livewire::test(JobsPage::class)
        ->mountAction('rollback_business_day_window')
        ->setActionData(['as_of' => '2026-02-06']);

    $html = $component->getMountedActionModalHtml();
    $dateLabelAt = strpos($html, __('Keep activity on'));
    $listAt = strpos($html, 'ff-rollback-events-list');

    expect($dateLabelAt)->not->toBeFalse()
        ->and($listAt)->not->toBeFalse()
        ->and($listAt)->toBeGreaterThan($dateLabelAt)
        ->and($html)->toContain($member->name)
        ->and($html)->toContain(__('Contributions'))
        ->and($html)->toContain(__('Cash-outs'))
        ->and($html)->toContain(__('Undo selected'))
        ->and($html)->toContain(MoneyDisplay::amount(500))
        ->and($html)->toContain(MoneyDisplay::amount(250))
        ->and($html)->toContain('ff-rollback-events-item')
        ->and($html)->toContain('ff-rollback-events-table')
        ->and($html)->toContain('fi-section')
        ->and($html)->toContain('fi-collapsible')
        ->and($html)->toContain('fi-section-collapse-btn')
        ->and($html)->toContain('ff-rollback-contributions')
        ->and($html)->toContain(__('Name'))
        ->and($html)->toContain(__('Amount'))
        ->and($contribution->fresh()->status)->toBe('posted')
        ->and($cashOut->fresh()->status)->toBe('accepted');
});

test('rollback modal keeps undo selected after a blocked submit', function () {
    Notification::fake();
    Filament::setCurrentPanel('tenant');

    $member = rollbackWindowMember($this->accounting, 800);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(2, 2026),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    expect(app(ContributionCollectionCycleService::class)->attemptCollection($contribution))->toBe('collected');

    $this->actingAs(User::create([
        'name' => 'Rollback Retry Admin',
        'email' => 'rollback-retry-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');

    $component = Livewire::test(JobsPage::class)
        ->mountAction('rollback_business_day_window')
        ->setActionData([
            'as_of' => '2026-02-06',
            'selected' => [],
        ])
        ->callMountedAction()
        ->mountAction('rollback_business_day_window')
        ->setActionData(['as_of' => '2026-02-06']);

    $html = $component->getMountedActionModalHtml();

    expect($html)->toContain(__('Undo selected'))
        ->and($contribution->fresh()->status)->toBe('posted');
});

test('rollback modal lists events for a long as-of window', function () {
    Filament::setCurrentPanel('tenant');

    $member = rollbackWindowMember($this->accounting, 800);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(2, 2026),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    expect(app(ContributionCollectionCycleService::class)->attemptCollection($contribution))->toBe('collected');

    $this->actingAs(User::create([
        'name' => 'Rollback Long Window Admin',
        'email' => 'rollback-long-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');

    $component = Livewire::test(JobsPage::class)
        ->mountAction('rollback_business_day_window')
        ->setActionData(['as_of' => '2025-11-05']);

    $html = $component->getMountedActionModalHtml();

    expect($html)->toContain(__('Keep activity on'))
        ->and($html)->toContain('ff-rollback-events-list')
        ->and($html)->toContain($member->name)
        ->and($html)->toContain(__('Contributions'))
        ->and($html)->not->toContain(__('All activity after this date will be undone. The event list is skipped for long windows so Confirm stays responsive.'))
        ->and($html)->toContain(__('Undo selected'));
});

test('rollback modal lists events when the business day matches a far as-of date', function () {
    Filament::setCurrentPanel('tenant');

    $member = rollbackWindowMember($this->accounting, 800);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(2, 2026),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    expect(app(ContributionCollectionCycleService::class)->attemptCollection($contribution))->toBe('collected');

    BusinessDaySettings::saveFromForm('2025-11-05');
    Carbon::setTestNow('2026-08-15 12:00:00');

    $this->actingAs(User::create([
        'name' => 'Rollback Setback Admin',
        'email' => 'rollback-setback-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');

    $html = Livewire::test(JobsPage::class)
        ->mountAction('rollback_business_day_window')
        ->getMountedActionModalHtml();

    expect($html)->toContain('ff-rollback-events-list')
        ->and($html)->toContain($member->name)
        ->and($html)->not->toContain(__('All activity after this date will be undone. The event list is skipped for long windows so Confirm stays responsive.'));
});

test('rollback modal queues a long window instead of hanging on confirm', function () {
    Queue::fake();
    Filament::setCurrentPanel('tenant');

    $member = rollbackWindowMember($this->accounting, 800);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(2, 2026),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    expect(app(ContributionCollectionCycleService::class)->attemptCollection($contribution))->toBe('collected');

    $admin = User::create([
        'name' => 'Rollback Queue Admin',
        'email' => 'rollback-queue-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $this->actingAs($admin, 'tenant');

    $selected = [
        BusinessDayWindowRollbackEventInventory::modelKey('contributions', $contribution->fresh()),
    ];

    Livewire::test(JobsPage::class)
        ->mountAction('rollback_business_day_window')
        ->setActionData([
            'as_of' => '2025-11-05',
            'selected' => $selected,
        ])
        ->callMountedAction()
        ->assertNotified(__('Rollback queued'));

    Queue::assertPushed(BusinessDayWindowRollbackJob::class, function (BusinessDayWindowRollbackJob $job) use ($admin, $selected): bool {
        return $job->asOfDate === '2025-11-05'
            && $job->notifyUserId === $admin->id
            && is_array($job->selectedKeys)
            && in_array($selected[0], $job->selectedKeys, true);
    });

    expect($contribution->fresh()->status)->toBe('posted');
});

test('rollback job undoes the window for the requester', function () {
    $member = rollbackWindowMember($this->accounting, 800);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => Contribution::periodDate(2, 2026),
        'amount' => 500,
        'amount_due' => 500,
        'amount_collected' => 0,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    expect(app(ContributionCollectionCycleService::class)->attemptCollection($contribution))->toBe('collected');

    $admin = User::create([
        'name' => 'Rollback Job Admin',
        'email' => 'rollback-job-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    (new BusinessDayWindowRollbackJob('2026-02-06', null, $admin->id))
        ->handle(app(BusinessDayWindowRollbackService::class));

    expect($contribution->fresh()->status)->toBe('pending')
        ->and($contribution->fresh()->collection_status)->toBe(ContributionCollectionStatus::PENDING);
});
