<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Loans\LoanLedgerService;
use App\Services\ReconciliationReportService;
use App\Support\LoanFundingStrategy;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    ReconciliationSnapshot::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
});

test('reconciliation report includes legacy check keys and control layer', function () {
    $report = app(ReconciliationReportService::class)->buildReport(
        ReconciliationSnapshot::MODE_REALTIME,
    );

    expect($report['checks'])->toHaveKeys([
        'ledger_balances',
        'global_trial',
        'paired_control_totals',
        'bank_statement_vs_book',
        'contributions_ledger',
        'member_portal_posting_integrity',
        'bank_transaction_posting_integrity',
        'bank_pipeline',
        'sms_transaction_posting_integrity',
        'active_loans_schedule_vs_ledger',
        'approved_loans_disbursement_vs_ledger',
        'loan_disbursement_cash_payout_integrity',
        'contribution_flow_integrity',
        'membership_application_fee_integrity',
        'subscription_fee_integrity',
        'loan_installment_flow_integrity',
        'member_cash_transfer_integrity',
        'orphan_loan_accounts',
        'collection_arrears_catalog',
    ])
        ->and($report['checks']['sms_transaction_posting_integrity']['severity'])->toBe('skipped')
        ->and($report)->toHaveKeys(['coverage_matrix', 'control_layer', 'pipeline', 'verdict'])
        ->and($report['verdict'])->toHaveKeys(['pass', 'critical_issues', 'warnings']);
});

test('global trial diagnostics surface suspected unbalanced posting groups', function () {
    $masterCash = Account::masterCash();
    expect($masterCash)->not->toBeNull();

    Transaction::factory()->for($masterCash)->credit()->create([
        'amount' => 500,
        'reference_type' => Contribution::class,
        'reference_id' => 9001,
        'description' => 'One-sided reconciliation test credit',
        'balance_after' => 500,
    ]);

    $report = app(ReconciliationReportService::class)->buildReport(
        ReconciliationSnapshot::MODE_REALTIME,
    );

    $check = $report['checks']['global_trial'];

    expect($check['severity'])->toBe('warning')
        ->and($check['delta'])->toBe(500.0)
        ->and($check['unbalanced_posting_group_count'])->toBe(1)
        ->and($check['suspected_postings'])->toHaveCount(1)
        ->and($check['suspected_postings'][0]['reference_type'])->toBe(Contribution::class)
        ->and($check['suspected_postings'][0]['reference_id'])->toBe(9001)
        ->and($check['suspected_postings'][0]['posting_delta'])->toBe(500.0)
        ->and($check['suspected_posting_lines'])->toHaveCount(1)
        ->and($check['suspected_posting_lines'][0]['transaction_id'])->toBeGreaterThan(0)
        ->and($check['suspected_posting_lines'][0]['reference_type'])->toBe(Contribution::class)
        ->and($check['resolution_hints'])->not->toBeEmpty();
});

test('global trial diagnostics exclude expected one-sided lifecycle reference groups', function () {
    $masterCash = Account::masterCash();
    expect($masterCash)->not->toBeNull();

    Transaction::factory()->for($masterCash)->credit()->create([
        'amount' => 500,
        'reference_type' => Contribution::class,
        'reference_id' => 9001,
        'description' => 'Suspicious contribution test credit',
        'balance_after' => 500,
    ]);

    Transaction::factory()->for($masterCash)->debit()->create([
        'amount' => 300,
        'reference_type' => CashOutRequest::class,
        'reference_id' => 134,
        'description' => 'Expected one-sided cash-out debit',
        'balance_after' => 200,
    ]);

    Transaction::factory()->for($masterCash)->credit()->create([
        'amount' => 250,
        'reference_type' => FundPosting::class,
        'reference_id' => 77,
        'description' => 'Expected one-sided deposit credit',
        'balance_after' => 450,
    ]);

    $report = app(ReconciliationReportService::class)->buildReport(
        ReconciliationSnapshot::MODE_REALTIME,
    );

    $check = $report['checks']['global_trial'];

    expect($check['severity'])->toBe('warning')
        ->and($check['unbalanced_posting_group_count'])->toBe(1)
        ->and($check['suspected_postings'])->toHaveCount(1)
        ->and($check['suspected_postings'][0]['reference_type'])->toBe(Contribution::class)
        ->and($check['suspected_postings'][0]['reference_id'])->toBe(9001)
        ->and(collect($check['suspected_postings'])->pluck('reference_type'))
        ->not->toContain(CashOutRequest::class, FundPosting::class);
});

test('global trial diagnostics exclude expected split-loan reference groups', function () {
    $masterCash = Account::masterCash();
    expect($masterCash)->not->toBeNull();

    $member = Member::create([
        'member_number' => 'RECON-SPLIT-LOAN',
        'name' => 'Split Loan Recon Member',
        'email' => 'split-loan-recon@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 300_000,
        'amount_requested' => 300_000,
        'amount_approved' => 300_000,
        'amount_disbursed' => 300_000,
        'member_portion' => 150_000,
        'master_portion' => 150_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 25_000,
        'total_repaid' => 0,
        'status' => 'active',
        'applied_at' => now()->subMonth(),
        'approved_at' => now()->subMonth(),
        'disbursed_at' => now()->subMonth(),
        'installments_count' => 12,
        'funding_strategy' => LoanFundingStrategy::SPLIT_PERCENTAGE,
    ]);

    Transaction::factory()->for($masterCash)->credit()->create([
        'amount' => 500,
        'reference_type' => Contribution::class,
        'reference_id' => 9001,
        'description' => 'Suspicious contribution test credit',
        'balance_after' => 500,
    ]);

    Transaction::factory()->for($masterCash)->debit()->create([
        'amount' => 300_000,
        'reference_type' => Loan::class,
        'reference_id' => $loan->id,
        'description' => 'Expected split-loan lifecycle debit',
        'balance_after' => -299_500,
    ]);

    $report = app(ReconciliationReportService::class)->buildReport(
        ReconciliationSnapshot::MODE_REALTIME,
    );

    $check = $report['checks']['global_trial'];

    expect($check['severity'])->toBe('warning')
        ->and($check['unbalanced_posting_group_count'])->toBe(1)
        ->and($check['suspected_postings'])->toHaveCount(1)
        ->and($check['suspected_postings'][0]['reference_type'])->toBe(Contribution::class)
        ->and($check['suspected_postings'][0]['reference_id'])->toBe(9001)
        ->and(collect($check['suspected_postings'])->pluck('reference_type'))
        ->not->toContain(Loan::class);
});

test('global trial diagnostics exclude expected loan repayment cash-flow groups', function () {
    $member = Member::create([
        'member_number' => 'RECON-REPAY-001',
        'name' => 'Repayment Recon Member',
        'email' => 'repayment-recon@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $masterCash = Account::masterCash();
    $masterFund = Account::masterFund();

    expect($masterCash)->not->toBeNull()
        ->and($masterFund)->not->toBeNull()
        ->and($member->cashAccount)->not->toBeNull()
        ->and($member->fundAccount)->not->toBeNull();

    Transaction::factory()->for($masterCash)->credit()->create([
        'amount' => 500,
        'reference_type' => Contribution::class,
        'reference_id' => 9001,
        'description' => 'Suspicious contribution test credit',
        'balance_after' => 500,
    ]);

    Transaction::factory()->for($masterCash)->credit()->create([
        'amount' => 300_000,
        'reference_type' => LoanRepayment::class,
        'reference_id' => 1727,
        'description' => 'Expected repayment cash-in master credit',
        'balance_after' => 300_500,
    ]);
    Transaction::factory()->for($member->cashAccount)->credit()->create([
        'amount' => 300_000,
        'reference_type' => LoanRepayment::class,
        'reference_id' => 1727,
        'description' => 'Expected repayment cash-in member credit',
        'balance_after' => 300_000,
    ]);
    Transaction::factory()->for($masterCash)->debit()->create([
        'amount' => 300_000,
        'reference_type' => LoanRepayment::class,
        'reference_id' => 1727,
        'description' => 'Expected repayment master debit',
        'balance_after' => 500,
    ]);
    Transaction::factory()->for($member->cashAccount)->debit()->create([
        'amount' => 300_000,
        'reference_type' => LoanRepayment::class,
        'reference_id' => 1727,
        'description' => 'Expected repayment member debit',
        'balance_after' => 0,
    ]);
    Transaction::factory()->for($masterFund)->credit()->create([
        'amount' => 300_000,
        'reference_type' => LoanRepayment::class,
        'reference_id' => 1727,
        'description' => 'Expected repayment master fund credit',
        'balance_after' => 300_000,
    ]);
    Transaction::factory()->for($member->fundAccount)->credit()->create([
        'amount' => 300_000,
        'reference_type' => LoanRepayment::class,
        'reference_id' => 1727,
        'description' => 'Expected repayment member fund credit',
        'balance_after' => 300_000,
    ]);

    $report = app(ReconciliationReportService::class)->buildReport(
        ReconciliationSnapshot::MODE_REALTIME,
    );

    $check = $report['checks']['global_trial'];

    expect($check['severity'])->toBe('warning')
        ->and($check['unbalanced_posting_group_count'])->toBe(1)
        ->and($check['suspected_postings'])->toHaveCount(1)
        ->and($check['suspected_postings'][0]['reference_type'])->toBe(Contribution::class)
        ->and(collect($check['suspected_postings'])->pluck('reference_type'))
        ->not->toContain(LoanRepayment::class);
});

test('global trial diagnostics exclude expected contribution cash-in plus allocation groups', function () {
    $member = Member::create([
        'member_number' => 'RECON-CONTRIB-001',
        'name' => 'Contribution Recon Member',
        'email' => 'contribution-recon@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $masterCash = Account::masterCash();
    $masterFund = Account::masterFund();

    expect($masterCash)->not->toBeNull()
        ->and($masterFund)->not->toBeNull()
        ->and($member->cashAccount)->not->toBeNull()
        ->and($member->fundAccount)->not->toBeNull();

    Transaction::factory()->for($masterCash)->credit()->create([
        'amount' => 500,
        'reference_type' => Contribution::class,
        'reference_id' => 9001,
        'description' => 'Suspicious contribution test credit',
        'balance_after' => 500,
    ]);

    Transaction::factory()->for($masterCash)->credit()->create([
        'amount' => 300_000,
        'reference_type' => Contribution::class,
        'reference_id' => 2779,
        'description' => 'Expected contribution master cash credit',
        'balance_after' => 300_500,
    ]);
    Transaction::factory()->for($member->cashAccount)->credit()->create([
        'amount' => 300_000,
        'reference_type' => Contribution::class,
        'reference_id' => 2779,
        'description' => 'Expected contribution member cash credit',
        'balance_after' => 300_000,
    ]);
    Transaction::factory()->for($masterCash)->debit()->create([
        'amount' => 300_000,
        'reference_type' => Contribution::class,
        'reference_id' => 2779,
        'description' => 'Expected contribution master cash debit',
        'balance_after' => 500,
    ]);
    Transaction::factory()->for($member->cashAccount)->debit()->create([
        'amount' => 300_000,
        'reference_type' => Contribution::class,
        'reference_id' => 2779,
        'description' => 'Expected contribution member cash debit',
        'balance_after' => 0,
    ]);
    Transaction::factory()->for($masterFund)->credit()->create([
        'amount' => 300_000,
        'reference_type' => Contribution::class,
        'reference_id' => 2779,
        'description' => 'Expected contribution master fund credit',
        'balance_after' => 300_000,
    ]);
    Transaction::factory()->for($member->fundAccount)->credit()->create([
        'amount' => 300_000,
        'reference_type' => Contribution::class,
        'reference_id' => 2779,
        'description' => 'Expected contribution member fund credit',
        'balance_after' => 300_000,
    ]);

    $report = app(ReconciliationReportService::class)->buildReport(
        ReconciliationSnapshot::MODE_REALTIME,
    );

    $check = $report['checks']['global_trial'];

    expect($check['severity'])->toBe('warning')
        ->and($check['unbalanced_posting_group_count'])->toBe(1)
        ->and($check['suspected_postings'])->toHaveCount(1)
        ->and($check['suspected_postings'][0]['reference_id'])->toBe(9001)
        ->and(collect($check['suspected_postings'])->pluck('reference_id'))
        ->not->toContain(2779);
});

test('global trial diagnostics list null-reference credit and debit lines', function () {
    $masterCash = Account::masterCash();
    expect($masterCash)->not->toBeNull();

    Transaction::factory()->for($masterCash)->credit()->create([
        'amount' => 300,
        'reference_type' => null,
        'reference_id' => null,
        'description' => 'Null-reference manual credit',
        'balance_after' => 300,
    ]);
    Transaction::factory()->for($masterCash)->debit()->create([
        'amount' => 100,
        'reference_type' => null,
        'reference_id' => null,
        'description' => 'Null-reference manual debit',
        'balance_after' => 200,
    ]);

    $report = app(ReconciliationReportService::class)->buildReport(
        ReconciliationSnapshot::MODE_REALTIME,
    );

    $check = $report['checks']['global_trial'];

    expect($check['severity'])->toBe('warning')
        ->and($check['delta'])->toBe(200.0)
        ->and($check['null_reference_line_count'])->toBe(2)
        ->and($check['null_reference_credits'])->toBe(300.0)
        ->and($check['null_reference_debits'])->toBe(100.0)
        ->and($check['null_reference_delta'])->toBe(200.0)
        ->and($check['null_reference_lines'])->toHaveCount(2)
        ->and(collect($check['null_reference_lines'])->pluck('type'))->toContain('credit', 'debit');
});

test('global trial omits diagnostics when credits and debits balance', function () {
    $masterCash = Account::masterCash();
    $masterFund = Account::masterFund();
    expect($masterCash)->not->toBeNull()
        ->and($masterFund)->not->toBeNull();

    Transaction::factory()->for($masterCash)->credit()->create([
        'amount' => 250,
        'reference_type' => Contribution::class,
        'reference_id' => 9002,
        'description' => 'Balanced test credit',
        'balance_after' => 250,
    ]);
    Transaction::factory()->for($masterFund)->debit()->create([
        'amount' => 250,
        'reference_type' => Contribution::class,
        'reference_id' => 9002,
        'description' => 'Balanced test debit',
        'balance_after' => -250,
    ]);

    $report = app(ReconciliationReportService::class)->buildReport(
        ReconciliationSnapshot::MODE_REALTIME,
    );

    $check = $report['checks']['global_trial'];

    expect($check['severity'])->toBe('ok')
        ->and($check)->not->toHaveKey('suspected_postings')
        ->and($check)->not->toHaveKey('resolution_hints');
});

test('paired control totals use pool-adjusted fund mirror with reserve accounts', function () {
    Account::masterFund()?->update(['balance' => 1800]);
    $masterInvest = Account::create(['type' => 'invest', 'name' => 'Master Invest', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'expense', 'name' => 'Master Expense', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'suspense', 'name' => 'Master Suspense', 'balance' => 0, 'is_master' => true]);

    app(AccountingService::class)->fundReserveAccountFromMasterFund(
        $masterInvest,
        500,
        'Capital allocation',
    );
    app(AccountingService::class)->recordInvestmentReturn(300, 'Q1 return');
    app(AccountingService::class)->fundReserveAccountFromMasterFund(
        Account::masterExpense(),
        300,
        'Operations float',
    );

    $member = Member::create([
        'member_number' => 'RECON-POOL-001',
        'name' => 'Pool Mirror Member',
        'email' => 'recon-pool@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    app(AccountingService::class)->credit($member->fundAccount, 1800, 'Seed fund');

    $report = app(ReconciliationReportService::class)->buildReport(
        ReconciliationSnapshot::MODE_REALTIME,
    );

    $check = $report['checks']['paired_control_totals'];

    expect($check['severity'])->toBe('ok')
        ->and($check['master_fund_balance'])->toBe(1300.0)
        ->and($check['master_fund_pool'])->toBe(1800.0)
        ->and($check['sum_member_fund'])->toBe(1800.0)
        ->and($check['fund_delta_abs'])->toBe(0.0)
        ->and($check['master_invest_balance'])->toBe(500.0)
        ->and($check['master_expense_balance'])->toBe(300.0)
        ->and($check['master_invest_return_to_fund_credits'])->toBe(300.0);
});

test('paired control totals diagnostics include mirror mismatch groups and related transactions', function () {
    $member = Member::create([
        'member_number' => 'RECON-POOL-002',
        'name' => 'Pool Drift Member',
        'email' => 'recon-pool-drift@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $masterCash = Account::masterCash();
    $masterFund = Account::masterFund();

    expect($masterCash)->not->toBeNull()
        ->and($masterFund)->not->toBeNull();

    app(AccountingService::class)->credit($masterCash, 100, 'Master cash mirror only', $member);
    app(AccountingService::class)->credit($member->cashAccount, 60, 'Member cash mirror only', $member);
    app(AccountingService::class)->credit($masterFund, 80, 'Master fund mirror only', $member);
    app(AccountingService::class)->credit($member->fundAccount, 50, 'Member fund mirror only', $member);

    $report = app(ReconciliationReportService::class)->buildReport(
        ReconciliationSnapshot::MODE_REALTIME,
    );

    $check = $report['checks']['paired_control_totals'];

    expect($check['severity'])->toBe('warning')
        ->and($check['cash_delta_abs'])->toBe(40.0)
        ->and($check['fund_delta_abs'])->toBe(30.0)
        ->and($check['cash_mirror_mismatches'])->not->toBeEmpty()
        ->and($check['cash_mirror_mismatches'][0]['reference_type'])->toBe(Member::class)
        ->and($check['cash_mirror_mismatches'][0]['reference_id'])->toBe($member->id)
        ->and($check['cash_mirror_mismatches'][0]['mirror_delta'])->toBe(40.0)
        ->and($check['fund_mirror_mismatches'])->not->toBeEmpty()
        ->and($check['fund_mirror_mismatches'][0]['mirror_delta'])->toBe(30.0)
        ->and(count($check['cash_related_transactions']))->toBeGreaterThan(0)
        ->and(count($check['fund_related_transactions']))->toBeGreaterThan(0)
        ->and($check['resolution_hints'])->not->toBeEmpty();
});

test('reconciliation report skips per-installment ledger checks for legacy imported loans', function () {
    $member = Member::create([
        'member_number' => 'RECON-LEGACY',
        'name' => 'Legacy Recon Member',
        'email' => 'legacy-recon@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(3),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 10_000,
        'amount_requested' => 10_000,
        'amount_approved' => 10_000,
        'amount_disbursed' => 10_000,
        'member_portion' => 4_000,
        'master_portion' => 6_000,
        'interest_rate' => 0,
        'term_months' => 2,
        'monthly_repayment' => 3000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => now()->subYear(),
        'approved_at' => now()->subYear(),
        'applied_at' => now()->subYear(),
        'installments_count' => 2,
    ]);

    $installment = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 3000,
        'due_date' => now()->subMonths(6)->toDateString(),
        'status' => 'paid',
        'paid_at' => now()->subMonths(6),
    ]);

    $repayment = LoanRepayment::create([
        'loan_id' => $loan->id,
        'amount' => 3000,
        'paid_at' => now()->subMonths(6),
        'notes' => 'legacy-import:test|legacy-recon@fund.test|2025-01-01|3000|loan_repayment|2025-01',
    ]);

    app(LoanLedgerService::class)->postImportedLoanRepaymentWithCashFlow(
        $loan->fresh(),
        $repayment,
        3000,
        now()->subMonths(6),
    );

    $report = app(ReconciliationReportService::class)->buildReport(
        ReconciliationSnapshot::MODE_REALTIME,
    );

    expect($report['checks']['loan_installment_flow_integrity']['severity'])->toBe('ok')
        ->and($report['checks']['loan_installment_flow_integrity']['legacy_import_loan_count'])->toBe(1);
});

test('reconciliation active loan check uses scheduled minus partial paid for ledger comparison', function () {
    $member = Member::create([
        'member_number' => 'RECON-PARTIAL',
        'name' => 'Partial Ahead Recon Member',
        'email' => 'partial-recon@fund.test',
        'monthly_contribution_amount' => 3000,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 75_000,
        'amount_requested' => 75_000,
        'amount_approved' => 150_000,
        'amount_disbursed' => 150_000,
        'member_portion' => 75_000,
        'master_portion' => 75_000,
        'repaid_to_master' => 40_000,
        'interest_rate' => 0,
        'term_months' => 25,
        'status' => 'active',
        'applied_at' => now()->subYear(),
    ]);

    app(LoanLedgerService::class)->ensureLoanAccount($loan);
    Account::query()->where('loan_id', $loan->id)->update(['balance' => -35_000]);

    foreach (range(1, 13) as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 3000,
            'due_date' => now()->subMonths(14 - $number),
            'status' => 'paid',
            'paid_at' => now()->subMonths(14 - $number),
        ]);
    }

    foreach (range(14, 25) as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 3000,
            'due_date' => now()->addMonths($number - 13),
            'status' => 'pending',
        ]);
    }

    $report = app(ReconciliationReportService::class)->buildReport(
        ReconciliationSnapshot::MODE_REALTIME,
    );

    $check = $report['checks']['active_loans_schedule_vs_ledger'];

    expect($check['severity'])->toBe('ok')
        ->and($check['mismatch_count'])->toBe(0)
        ->and($loan->fresh(['installments'])->getOutstandingBreakdown())->toMatchArray([
            'scheduled' => 36_000.0,
            'partial_paid' => 1_000.0,
            'ledger' => 35_000.0,
            'has_split' => true,
        ]);
});

test('reconciliation snapshot persists report payload', function () {
    $service = app(ReconciliationReportService::class);
    $report = $service->buildReport(ReconciliationSnapshot::MODE_DAILY);

    $snapshot = $service->persistSnapshot($report, null);

    expect($snapshot->fresh())
        ->mode->toBe(ReconciliationSnapshot::MODE_DAILY)
        ->is_passing->toBe($report['verdict']['pass'])
        ->report->toBeArray()
        ->and($snapshot->report['checks']['ledger_balances']['severity'])->toBe('ok');
});

test('fund reconcile command stores daily snapshot by default', function () {
    Artisan::call('fund:reconcile --daily');

    expect(ReconciliationSnapshot::query()->count())->toBe(1)
        ->and(ReconciliationSnapshot::query()->value('mode'))->toBe(ReconciliationSnapshot::MODE_DAILY);
});

test('fund reconcile command respects no-store flag', function () {
    Artisan::call('fund:reconcile --realtime --no-store');

    expect(ReconciliationSnapshot::query()->count())->toBe(0);
});

test('bank options merge reconciliation settings group', function () {
    Setting::set('reconciliation', 'bank_statement_balance', '15000.50');
    Setting::set('reconciliation', 'bank_statement_date', '2026-05-31');
    Setting::set('reconciliation', 'bank_variance_critical', '1');

    $options = ReconciliationReportService::bankOptionsFromSettings();

    expect($options)->toMatchArray([
        'declared_bank_balance' => 15000.5,
        'declared_bank_date' => '2026-05-31',
        'bank_mismatch_treat_as_critical' => true,
    ]);
});

test('reconciliation page workspace tabs switch via livewire', function () {
    $admin = User::create([
        'name' => 'Recon Tabs Admin',
        'email' => 'recon-tabs@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Filament::setCurrentPanel('tenant');
    $this->actingAs($admin, 'tenant');

    Livewire::test(ReconciliationOverviewPage::class)
        ->assertSet('sideTab', 'overview')
        ->call('setSideTab', 'exceptions')
        ->assertSet('sideTab', 'exceptions')
        ->call('setSideTab', 'history')
        ->assertSet('sideTab', 'history')
        ->call('setSideTab', 'overview')
        ->assertSet('sideTab', 'overview')
        ->call('setAdvancedUi', true)
        ->call('setSideTab', 'snapshots')
        ->assertSet('sideTab', 'snapshots')
        ->call('setSideTab', 'methodology')
        ->assertSet('sideTab', 'methodology');
});

test('run check now completes in simple mode', function () {
    $admin = User::create([
        'name' => 'Recon Simple Run Admin',
        'email' => 'recon-simple-run-'.uniqid('', true).'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Filament::setCurrentPanel('tenant');
    $this->actingAs($admin, 'tenant');

    $before = ReconciliationSnapshot::query()->count();

    Livewire::test(ReconciliationOverviewPage::class)
        ->assertSet('advancedUi', false)
        ->assertSee(__('Run check now'))
        ->call('runCheckNow')
        ->assertNotified()
        ->assertSet('mountedActions', [])
        ->call('setSideTab', 'exceptions')
        ->assertSet('sideTab', 'exceptions')
        ->call('setSideTab', 'overview')
        ->assertSet('sideTab', 'overview');

    expect(ReconciliationSnapshot::query()->count())->toBe($before + 1);
});

test('reconciliation overview renders page action modals in advanced mode', function () {
    $admin = User::create([
        'name' => 'Recon Modal Admin',
        'email' => 'recon-modal-'.uniqid('', true).'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Filament::setCurrentPanel('tenant');
    $this->actingAs($admin, 'tenant');

    Livewire::test(ReconciliationOverviewPage::class)
        ->call('setAdvancedUi', true)
        ->assertSet('sideTab', 'overview')
        ->assertSeeHtml('wire:partial="action-modals"');
});

test('real-time snapshot action completes and tabs remain switchable', function () {
    $admin = User::create([
        'name' => 'Recon Run Admin',
        'email' => 'recon-run-'.uniqid('', true).'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Filament::setCurrentPanel('tenant');
    $this->actingAs($admin, 'tenant');

    $before = ReconciliationSnapshot::query()->count();

    Livewire::test(ReconciliationOverviewPage::class)
        ->call('setAdvancedUi', true)
        ->call('setSideTab', 'exceptions')
        ->assertSet('sideTab', 'exceptions')
        ->call('setSideTab', 'overview')
        ->assertSet('sideTab', 'overview')
        ->call('setSideTab', 'history')
        ->assertSet('sideTab', 'history')
        ->mountAction('run_realtime')
        ->callMountedAction()
        ->assertNotified()
        ->assertSet('mountedActions', [])
        ->assertSet('sideTab', 'snapshots')
        ->assertSee(__('Snapshot analysis'))
        ->assertSee(__('Check results'))
        ->call('setSideTab', 'exceptions')
        ->assertSet('sideTab', 'exceptions')
        ->call('setSideTab', 'overview')
        ->assertSet('sideTab', 'overview')
        ->call('setSideTab', 'methodology')
        ->assertSet('sideTab', 'methodology');

    expect(ReconciliationSnapshot::query()->count())->toBe($before + 1);
});

test('reconciliation exceptions tab selects issue and shows analysis panel', function () {
    ReconciliationException::query()->delete();

    $admin = User::create([
        'name' => 'Recon Exception Admin',
        'email' => 'recon-exception-'.uniqid('', true).'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $first = ReconciliationException::create([
        'exception_code' => 'RECON_AMBIGUOUS_MATCH',
        'domain' => 'bank_clearing',
        'severity' => 'high',
        'status' => ReconciliationException::STATUS_OPEN,
        'raised_at' => now()->subHour(),
        'affected_entities' => [
            'imported_bank_transaction_id' => 99,
            'candidate_ids' => [1, 2],
        ],
    ]);

    $second = ReconciliationException::create([
        'exception_code' => 'MEMBER_CASH_DRIFT',
        'domain' => 'master_account',
        'severity' => 'critical',
        'status' => ReconciliationException::STATUS_OPEN,
        'raised_at' => now(),
    ]);

    Filament::setCurrentPanel('tenant');
    $this->actingAs($admin, 'tenant');

    Livewire::test(ReconciliationOverviewPage::class)
        ->call('setSideTab', 'exceptions')
        ->assertSet('sideTab', 'exceptions')
        ->assertSet('selectedExceptionId', $second->id)
        ->assertSee(__('Issue analysis'))
        ->assertSee(__('Suggested next step'))
        ->assertSee(__('Fix actions'))
        ->call('selectException', (string) $first->id)
        ->assertSet('selectedExceptionId', $first->id)
        ->assertSee(__('Ambiguous bank match'))
        ->call('selectException', $second->id)
        ->assertSet('selectedExceptionId', $second->id)
        ->assertSee(__('Member cash drift'))
        ->call('setQueueDomainFilter', 'bank_clearing')
        ->assertSet('queueDomainFilter', 'bank_clearing')
        ->assertSet('selectedExceptionId', $first->id)
        ->call('setQueueDomainFilter', 'bank_clearing')
        ->assertSet('queueDomainFilter', null);
});

test('admin can delete a reconciliation snapshot from snapshots tab', function () {
    $admin = User::create([
        'name' => 'Recon Delete Admin',
        'email' => 'recon-delete-'.uniqid('', true).'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $older = ReconciliationSnapshot::create([
        'mode' => ReconciliationSnapshot::MODE_DAILY,
        'as_of' => now()->subDay(),
        'is_passing' => true,
        'critical_issues' => 0,
        'warnings' => 0,
        'summary' => [],
        'report' => ['verdict' => ['pass' => true]],
    ]);

    $newer = ReconciliationSnapshot::create([
        'mode' => ReconciliationSnapshot::MODE_REALTIME,
        'as_of' => now(),
        'is_passing' => false,
        'critical_issues' => 1,
        'warnings' => 0,
        'summary' => [],
        'report' => ['verdict' => ['pass' => false]],
    ]);

    Filament::setCurrentPanel('tenant');
    $this->actingAs($admin, 'tenant');

    Livewire::test(ReconciliationOverviewPage::class)
        ->call('setAdvancedUi', true)
        ->call('setSideTab', 'snapshots')
        ->assertSet('selectedSnapshotId', $newer->id)
        ->call('deleteSnapshot', $newer->id)
        ->assertNotified()
        ->assertSet('selectedSnapshotId', $older->id);

    expect(ReconciliationSnapshot::query()->pluck('id')->all())->toBe([$older->id]);
});

test('admin can bulk delete reconciliation snapshots', function () {
    $admin = User::create([
        'name' => 'Recon Bulk Delete Admin',
        'email' => 'recon-bulk-delete-'.uniqid('', true).'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    $keep = ReconciliationSnapshot::create([
        'mode' => ReconciliationSnapshot::MODE_DAILY,
        'as_of' => now()->subDays(2),
        'is_passing' => true,
        'critical_issues' => 0,
        'warnings' => 0,
        'summary' => [],
        'report' => ['verdict' => ['pass' => true]],
    ]);

    $deleteOne = ReconciliationSnapshot::create([
        'mode' => ReconciliationSnapshot::MODE_REALTIME,
        'as_of' => now()->subDay(),
        'is_passing' => true,
        'critical_issues' => 0,
        'warnings' => 0,
        'summary' => [],
        'report' => ['verdict' => ['pass' => true]],
    ]);

    $deleteTwo = ReconciliationSnapshot::create([
        'mode' => ReconciliationSnapshot::MODE_MONTHLY,
        'as_of' => now(),
        'is_passing' => false,
        'critical_issues' => 2,
        'warnings' => 1,
        'summary' => [],
        'report' => ['verdict' => ['pass' => false]],
    ]);

    Filament::setCurrentPanel('tenant');
    $this->actingAs($admin, 'tenant');

    Livewire::test(ReconciliationOverviewPage::class)
        ->call('setAdvancedUi', true)
        ->call('setSideTab', 'snapshots')
        ->set('snapshotBulkSelection', [$deleteOne->id, $deleteTwo->id])
        ->call('deleteSelectedSnapshots')
        ->assertNotified()
        ->assertSet('snapshotBulkSelection', [])
        ->assertSet('selectedSnapshotId', $keep->id);

    expect(ReconciliationSnapshot::query()->pluck('id')->all())->toBe([$keep->id]);
});

test('non-admin cannot delete reconciliation snapshots', function () {
    $memberUser = User::create([
        'name' => 'Recon Member',
        'email' => 'recon-member-'.uniqid('', true).'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => false,
    ]);

    $snapshot = ReconciliationSnapshot::create([
        'mode' => ReconciliationSnapshot::MODE_REALTIME,
        'as_of' => now(),
        'is_passing' => true,
        'critical_issues' => 0,
        'warnings' => 0,
        'summary' => [],
        'report' => ['verdict' => ['pass' => true]],
    ]);

    Filament::setCurrentPanel('tenant');
    $this->actingAs($memberUser, 'tenant');

    Livewire::test(ReconciliationOverviewPage::class)
        ->call('deleteSnapshot', $snapshot->id)
        ->assertForbidden();

    expect(ReconciliationSnapshot::query()->whereKey($snapshot->id)->exists())->toBeTrue();
});

test('bank pipeline unposted excludes synthetic operational clearance rows', function () {
    $syntheticStatement = BankStatement::create([
        'filename' => 'member-cash-outs',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    BankTransaction::create([
        'bank_statement_id' => $syntheticStatement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Synthetic cash-out clearance row',
        'amount' => -30000,
        'status' => 'imported',
        'hash' => md5('synthetic-cash-out-unposted'),
        'is_cleared' => false,
    ]);

    $realStatement = BankStatement::create([
        'filename' => 'bank-export-may.csv',
        'bank_name' => 'Test Bank',
        'status' => 'completed',
        'total_rows' => 1,
        'imported_rows' => 1,
        'duplicate_rows' => 0,
    ]);

    BankTransaction::create([
        'bank_statement_id' => $realStatement->id,
        'transaction_date' => now()->toDateString(),
        'description' => 'Real CSV import awaiting mirror',
        'amount' => 4500,
        'status' => 'imported',
        'hash' => md5('real-csv-unposted'),
        'is_cleared' => true,
        'cleared_at' => now(),
    ]);

    $report = app(ReconciliationReportService::class)->buildReport(
        ReconciliationSnapshot::MODE_REALTIME,
    );

    expect($report['pipeline']['bank_unposted_count'])->toBe(1)
        ->and($report['pipeline']['bank_unposted_amount'])->toBe(4500.0)
        ->and($report['checks']['bank_pipeline']['severity'])->toBe('warning')
        ->and($report['verdict']['warnings'])->toBe(
            collect($report['checks'])->filter(fn (array $check): bool => ($check['severity'] ?? '') === 'warning')->count(),
        );
});
