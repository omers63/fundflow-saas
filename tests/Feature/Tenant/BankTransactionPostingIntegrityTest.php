<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Services\BankClearingMatchService;
use App\Services\ContributionCollectionCycleService;
use App\Services\FundPostingService;
use App\Services\MasterExpenseDisbursementService;
use App\Services\MemberCashOutService;
use App\Services\MembershipApplicationApprovalService;
use App\Services\ReconciliationReportService;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    FundPosting::query()->delete();
    BankTransaction::query()->delete();
    BankStatement::query()->delete();
    Transaction::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 50_000, 'is_master' => true]);
    Account::create(['type' => 'bank', 'name' => 'Master Bank', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'expense', 'name' => 'Master Expense', 'balance' => 5_000, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $this->accounting = app(AccountingService::class);
});

test('matched cash-out bank rows pass posting integrity without master cash on the bank line', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 0,
    ]);
    $this->accounting->createMemberAccounts($member);

    $collection = Mockery::mock(ContributionCollectionCycleService::class);
    $collection->shouldReceive('onMemberCashIncreased')->andReturnNull();
    app()->instance(ContributionCollectionCycleService::class, $collection);

    $this->accounting->creditMemberCashWithMasterMirror(
        $member->cashAccount,
        1_000,
        'Seed cash',
        __('(seed mirror)'),
        null,
        null,
        $member->id,
    );

    $cashOuts = app(MemberCashOutService::class);
    $request = MemberCashOutService::withoutNotifications(
        fn () => $cashOuts->submit($member->fresh(), 400, 'Need funds'),
    );
    MemberCashOutService::withoutNotifications(
        fn () => $cashOuts->accept($request->fresh(), reviewedBy: null),
    );

    $uncleared = $request->fresh()->bankTransaction;
    $statement = BankStatement::create([
        'filename' => 'cash-out-integrity.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);
    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Withdrawal',
        'amount' => -400,
        'status' => 'imported',
        'hash' => md5('cash-out-integrity-import'),
        'is_cleared' => false,
    ]);

    app(BankClearingMatchService::class)->clearMatchPair($uncleared->fresh(), $imported->fresh());

    $imported = $imported->fresh();

    expect($imported->status)->toBe('posted')
        ->and($imported->master_cash_transaction_id)->toBeNull()
        ->and($imported->master_bank_transaction_id)->not->toBeNull();

    $check = app(ReconciliationReportService::class)
        ->buildReport(ReconciliationSnapshot::MODE_REALTIME)['checks']['bank_transaction_posting_integrity'];

    expect($check['severity'])->toBe('ok')
        ->and($check['issue_count'])->toBe(0)
        ->and($check['transactions_checked'])->toBeGreaterThan(0);
});

test('matched expense bank rows pass posting integrity as match-only', function () {
    $disbursement = app(MasterExpenseDisbursementService::class)->disburse(
        Account::masterExpense(),
        350,
        'Vendor check',
    );

    $uncleared = $disbursement->bankTransaction;
    $statement = BankStatement::create([
        'filename' => 'expense-integrity.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);
    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Vendor paid',
        'amount' => -350,
        'status' => 'imported',
        'hash' => md5('expense-integrity-import'),
        'is_cleared' => false,
    ]);

    app(BankClearingMatchService::class)->clearMatchPair($uncleared->fresh(), $imported->fresh());

    $imported = $imported->fresh();

    expect($imported->status)->toBe('posted')
        ->and($imported->master_cash_transaction_id)->toBeNull()
        ->and($imported->master_bank_transaction_id)->toBeNull()
        ->and($imported->expense_disbursement_id)->toBe($disbursement->id);

    $check = app(ReconciliationReportService::class)
        ->buildReport(ReconciliationSnapshot::MODE_REALTIME)['checks']['bank_transaction_posting_integrity'];

    expect($check['severity'])->toBe('ok')
        ->and($check['issue_count'])->toBe(0);
});

test('accepted deposit ops row does not require master bank before or after csv match', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 0,
    ]);
    $this->accounting->createMemberAccounts($member);

    $collection = Mockery::mock(ContributionCollectionCycleService::class);
    $collection->shouldReceive('onMemberCashIncreased')->andReturnNull();
    app()->instance(ContributionCollectionCycleService::class, $collection);

    $fundPostings = app(FundPostingService::class);
    $posting = $fundPostings->submit($member, 6, now()->toDateString(), reference: 'dep-6');
    $opsLine = $posting->fresh()->bankTransaction;
    expect($opsLine)->not->toBeNull();

    // Match CSV before admin accept (bank clearing first), then approve.
    $statement = BankStatement::create([
        'filename' => 'deposit-integrity.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);
    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Deposit from member 23',
        'amount' => 6,
        'status' => 'imported',
        'hash' => md5('deposit-integrity-import'),
        'is_cleared' => false,
    ]);

    app(BankClearingMatchService::class)->clearMatchPair($opsLine->fresh(), $imported->fresh());
    $fundPostings->accept($posting->fresh());

    $opsLine = $opsLine->fresh();
    $imported = $imported->fresh();

    expect($opsLine->status)->toBe('posted')
        ->and($opsLine->is_cleared)->toBeTrue()
        ->and($opsLine->master_bank_transaction_id)->toBeNull()
        ->and($imported->status)->toBe('posted')
        ->and($imported->fund_posting_id)->toBe($posting->id)
        ->and($imported->master_bank_transaction_id)->not->toBeNull();

    $check = app(ReconciliationReportService::class)
        ->buildReport(ReconciliationSnapshot::MODE_REALTIME)['checks']['bank_transaction_posting_integrity'];

    expect($check['severity'])->toBe('ok')
        ->and($check['issue_count'])->toBe(0)
        ->and(collect($check['issues'])->pluck('bank_transaction_id')->all())
        ->not->toContain($opsLine->id, $imported->id);
});

test('accepted uncleared deposit ops row passes posting integrity without master bank', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 0,
    ]);
    $this->accounting->createMemberAccounts($member);

    $collection = Mockery::mock(ContributionCollectionCycleService::class);
    $collection->shouldReceive('onMemberCashIncreased')->andReturnNull();
    app()->instance(ContributionCollectionCycleService::class, $collection);

    $fundPostings = app(FundPostingService::class);
    $posting = $fundPostings->submit($member, 6, now()->toDateString());
    $fundPostings->accept($posting->fresh());

    $opsLine = $posting->fresh()->bankTransaction;

    expect($opsLine->status)->toBe('posted')
        ->and($opsLine->is_cleared)->toBeFalse()
        ->and($opsLine->master_bank_transaction_id)->toBeNull();

    $check = app(ReconciliationReportService::class)
        ->buildReport(ReconciliationSnapshot::MODE_REALTIME)['checks']['bank_transaction_posting_integrity'];

    expect($check['severity'])->toBe('ok')
        ->and(collect($check['issues'])->pluck('bank_transaction_id'))->not->toContain($opsLine->id);
});

test('matched cash-out without master bank ledger is critical', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 0,
    ]);
    $this->accounting->createMemberAccounts($member);

    $collection = Mockery::mock(ContributionCollectionCycleService::class);
    $collection->shouldReceive('onMemberCashIncreased')->andReturnNull();
    app()->instance(ContributionCollectionCycleService::class, $collection);

    $this->accounting->creditMemberCashWithMasterMirror(
        $member->cashAccount,
        500,
        'Seed cash',
        __('(seed mirror)'),
        null,
        null,
        $member->id,
    );

    $cashOuts = app(MemberCashOutService::class);
    $request = MemberCashOutService::withoutNotifications(
        fn () => $cashOuts->submit($member->fresh(), 200, 'Need funds'),
    );
    MemberCashOutService::withoutNotifications(
        fn () => $cashOuts->accept($request->fresh(), reviewedBy: null),
    );

    $uncleared = $request->fresh()->bankTransaction;
    $statement = BankStatement::create([
        'filename' => 'cash-out-missing-bank.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);
    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Withdrawal',
        'amount' => -200,
        'status' => 'imported',
        'hash' => md5('cash-out-missing-bank-import'),
        'is_cleared' => false,
    ]);

    app(BankClearingMatchService::class)->clearMatchPair($uncleared->fresh(), $imported->fresh());

    $imported = $imported->fresh();
    $imported->forceFill(['master_bank_transaction_id' => null])->saveQuietly();
    $imported->transactions()->delete();

    $check = app(ReconciliationReportService::class)
        ->buildReport(ReconciliationSnapshot::MODE_REALTIME)['checks']['bank_transaction_posting_integrity'];

    expect($check['severity'])->toBe('critical')
        ->and($check['issue_count'])->toBeGreaterThan(0)
        ->and(collect($check['issues'])->pluck('issue'))
        ->toContain('matched clearance bank row missing master bank ledger line');
});

test('accepted deposit passes member portal cash mirror integrity with null-ref master cash', function () {
    $member = Member::factory()->create([
        'status' => 'active',
        'monthly_contribution_amount' => 0,
    ]);
    $this->accounting->createMemberAccounts($member);

    $collection = Mockery::mock(ContributionCollectionCycleService::class);
    $collection->shouldReceive('onMemberCashIncreased')->andReturnNull();
    app()->instance(ContributionCollectionCycleService::class, $collection);

    $fundPostings = app(FundPostingService::class);
    $posting = $fundPostings->submit($member, 6, now()->toDateString());
    $fundPostings->accept($posting->fresh());

    $memberCash = Transaction::query()
        ->where('reference_type', FundPosting::class)
        ->where('reference_id', $posting->id)
        ->whereHas('account', fn ($q) => $q->where('type', 'cash')->where('is_master', false))
        ->first();
    $masterCash = Transaction::query()
        ->where('account_id', Account::masterCash()->id)
        ->where('type', 'credit')
        ->where('amount', 6)
        ->whereNull('reference_type')
        ->first();

    expect($memberCash)->not->toBeNull()
        ->and($masterCash)->not->toBeNull()
        ->and($masterCash->reference_id)->toBeNull();

    $check = app(ReconciliationReportService::class)
        ->buildReport(ReconciliationSnapshot::MODE_REALTIME)['checks']['member_portal_posting_integrity'];

    expect($check['severity'])->toBe('ok')
        ->and($check['issue_count'])->toBe(0)
        ->and(collect($check['issues'])->pluck('fund_posting_id'))->not->toContain($posting->id);
});

test('subscription fee operational bank row passes posting integrity without master cash', function () {
    $application = MembershipApplication::create([
        'name' => 'Fee Integrity Applicant',
        'email' => 'fee-integrity@example.com',
        'password' => 'SecurePass1!',
        'phone' => '+966501111111',
        'application_type' => 'new',
        'national_id' => '1111222233',
        'date_of_birth' => '1991-02-01',
        'address' => '1 Fee St',
        'city' => 'Riyadh',
        'mobile_phone' => '+966501111111',
        'bank_account_number' => '1234567890123456',
        'iban' => 'SA0380000000608010167519',
        'membership_fee_amount' => 75,
        'membership_fee_required_amount' => 50,
        'membership_fee_transfer_date' => now()->toDateString(),
        'membership_fee_transfer_reference' => 'FEE-INT-OPS',
        'membership_fee_receipt_path' => 'applications/receipts/fee-int.jpg',
        'status' => 'pending',
    ]);

    app(MembershipApplicationApprovalService::class)->approve($application);

    $opsLine = BankTransaction::query()
        ->where('membership_application_id', $application->fresh()->id)
        ->first();

    expect($opsLine)->not->toBeNull()
        ->and($opsLine->status)->toBe('posted')
        ->and($opsLine->is_cleared)->toBeFalse()
        ->and($opsLine->master_cash_transaction_id)->toBeNull();

    $check = app(ReconciliationReportService::class)
        ->buildReport(ReconciliationSnapshot::MODE_REALTIME)['checks']['bank_transaction_posting_integrity'];

    expect($check['severity'])->toBe('ok')
        ->and(collect($check['issues'])->pluck('bank_transaction_id'))->not->toContain($opsLine->id);
});

test('matched subscription fee import requires master bank ledger for integrity', function () {
    $application = MembershipApplication::create([
        'name' => 'Fee Integrity Match Applicant',
        'email' => 'fee-integrity-match@example.com',
        'password' => 'SecurePass1!',
        'phone' => '+966502222222',
        'application_type' => 'new',
        'national_id' => '2222333344',
        'date_of_birth' => '1992-03-01',
        'address' => '2 Fee St',
        'city' => 'Riyadh',
        'mobile_phone' => '+966502222222',
        'bank_account_number' => '1234567890123456',
        'iban' => 'SA0380000000608010167519',
        'membership_fee_amount' => 75,
        'membership_fee_required_amount' => 50,
        'membership_fee_transfer_date' => now()->toDateString(),
        'membership_fee_transfer_reference' => 'FEE-INT-MATCH',
        'membership_fee_receipt_path' => 'applications/receipts/fee-int-match.jpg',
        'status' => 'pending',
    ]);

    app(MembershipApplicationApprovalService::class)->approve($application);

    $opsLine = BankTransaction::query()
        ->where('membership_application_id', $application->fresh()->id)
        ->first();

    $statement = BankStatement::create([
        'filename' => 'fee-integrity-match.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);
    $imported = BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Imported fee',
        'amount' => 75,
        'status' => 'imported',
        'hash' => md5('fee-integrity-match-import'),
        'is_cleared' => false,
    ]);

    app(BankClearingMatchService::class)->clearMatchPair($opsLine->fresh(), $imported->fresh());

    $imported = $imported->fresh();

    expect($imported->status)->toBe('posted')
        ->and($imported->membership_application_id)->toBe($application->fresh()->id)
        ->and($imported->master_bank_transaction_id)->not->toBeNull();

    $okCheck = app(ReconciliationReportService::class)
        ->buildReport(ReconciliationSnapshot::MODE_REALTIME)['checks']['bank_transaction_posting_integrity'];

    expect($okCheck['severity'])->toBe('ok')
        ->and(collect($okCheck['issues'])->pluck('bank_transaction_id')->all())
        ->not->toContain($opsLine->id, $imported->id);

    $imported->forceFill(['master_bank_transaction_id' => null])->saveQuietly();
    $imported->transactions()->delete();

    $badCheck = app(ReconciliationReportService::class)
        ->buildReport(ReconciliationSnapshot::MODE_REALTIME)['checks']['bank_transaction_posting_integrity'];

    expect($badCheck['severity'])->toBe('critical')
        ->and(collect($badCheck['issues'])->pluck('issue'))
        ->toContain('matched clearance bank row missing master bank ledger line');
});
