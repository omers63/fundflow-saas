<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
use App\Models\Tenant\Account;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\Loans\LoanLedgerService;
use App\Services\ReconciliationReportService;
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
    ])
        ->and($report['checks']['sms_transaction_posting_integrity']['severity'])->toBe('skipped')
        ->and($report)->toHaveKeys(['coverage_matrix', 'control_layer', 'pipeline', 'verdict'])
        ->and($report['verdict'])->toHaveKeys(['pass', 'critical_issues', 'warnings']);
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

test('run check now action completes in simple mode', function () {
    $admin = User::create([
        'name' => 'Recon Simple Run Admin',
        'email' => 'recon-simple-run-' . uniqid('', true) . '@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Filament::setCurrentPanel('tenant');
    $this->actingAs($admin, 'tenant');

    $before = ReconciliationSnapshot::query()->count();

    Livewire::test(ReconciliationOverviewPage::class)
        ->assertSet('advancedUi', false)
        ->mountAction('run_realtime')
        ->callMountedAction()
        ->assertNotified()
        ->assertSet('mountedActions', [])
        ->call('setSideTab', 'exceptions')
        ->assertSet('sideTab', 'exceptions');

    expect(ReconciliationSnapshot::query()->count())->toBe($before + 1);
});

test('real-time snapshot action completes and tabs remain switchable', function () {
    $admin = User::create([
        'name' => 'Recon Run Admin',
        'email' => 'recon-run-' . uniqid('', true) . '@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Filament::setCurrentPanel('tenant');
    $this->actingAs($admin, 'tenant');

    $before = ReconciliationSnapshot::query()->count();

    Livewire::test(ReconciliationOverviewPage::class)
        ->call('setAdvancedUi', true)
        ->mountAction('run_realtime')
        ->callMountedAction()
        ->assertNotified()
        ->assertSet('mountedActions', [])
        ->call('setSideTab', 'exceptions')
        ->assertSet('sideTab', 'exceptions')
        ->call('setSideTab', 'overview')
        ->assertSet('sideTab', 'overview');

    expect(ReconciliationSnapshot::query()->count())->toBe($before + 1);
});
