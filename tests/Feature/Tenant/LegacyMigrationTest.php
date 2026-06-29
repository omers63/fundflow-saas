<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\LegacyMigrationPage;
use App\Jobs\Tenant\RunLegacyMigrationPaymentsJob;
use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanRepayment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionService;
use App\Services\LegacyMigration\LegacyExcessLoanRepaymentRepairService;
use App\Services\LegacyMigration\LegacyImportedLoanInstallmentRebuildService;
use App\Services\LegacyMigration\LegacyImportedLoanScheduleSyncService;
use App\Services\LegacyMigration\LegacyLoanDisbursementPortionRepairService;
use App\Services\LegacyMigration\LegacyLoanRepaymentTarget;
use App\Services\LegacyMigration\LegacyMigrationCashSupplementRepairService;
use App\Services\LegacyMigration\LegacyMigrationLoanFundingSimulator;
use App\Services\LegacyMigration\LegacyMigrationOrchestrator;
use App\Services\LegacyMigration\LegacyMigrationPreviewService;
use App\Services\LegacyMigration\LegacyMigrationZeroBalanceLoanCompletionService;
use App\Services\LegacyMigration\LegacyMisclassifiedContributionRepairService;
use App\Services\LegacyMigration\LegacyPaymentClassifierService;
use App\Services\LegacyMigration\LegacyPaymentImportService;
use App\Services\Loans\LoanImportService;
use App\Services\Loans\LoanLedgerService;
use App\Services\MemberImportService;
use App\Support\AssociativeCsv;
use App\Support\FilamentStoredUploadPath;
use App\Support\LegacyMigrationDateFormatSettings;
use App\Support\LegacyMigrationDateParser;
use App\Support\LegacyMigrationFundingStrategySettings;
use App\Support\LegacyMigrationGraceCycleSettings;
use App\Support\LegacyMigrationSampleCsv;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use Carbon\Carbon;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    app()->setLocale('en');

    Member::query()->delete();
    User::query()->delete();

    Setting::query()->where('group', LegacyMigrationDateFormatSettings::GROUP)->delete();

    $this->admin = User::create([
        'name' => 'Migration Admin',
        'email' => 'migration-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
});

test('filament stored upload path resolves uuid keyed stored paths', function () {
    Storage::disk('local')->put('legacy-migration/members.csv', 'name,email');

    $resolved = FilamentStoredUploadPath::tryResolveReadableCsvToAbsolutePath([
        'livewire-file:abc' => 'legacy-migration/members.csv',
    ]);

    expect($resolved)->not->toBeNull()
        ->and($resolved['relativePathForDeletion'])->toBe('legacy-migration/members.csv')
        ->and(is_readable($resolved['absolutePath']))->toBeTrue();

    Storage::disk('local')->delete('legacy-migration/members.csv');
});

test('tenant admin can access legacy migration page', function () {
    Filament::setCurrentPanel('tenant');

    Setting::set('legacy_migration', 'members_imported', '1');

    Livewire::actingAs($this->admin, 'tenant')
        ->test(LegacyMigrationPage::class)
        ->assertSuccessful()
        ->assertSee(__('Step 1: Import members'))
        ->call('goToStep', 2)
        ->assertSee(__('Step 2: Import loans'));
});

test('legacy migration preview service reads stored member csv upload', function () {
    $path = storage_path('app/legacy-migration-preview-members.csv');
    file_put_contents($path, implode("\n", [
        'member_number,name,email',
        'LEG-PREVIEW,Preview Member,preview-member@fund.test',
    ]));

    $preview = app(LegacyMigrationPreviewService::class)->previewMembers($path);

    expect($preview['row_count'])->toBe(1)
        ->and($preview['missing_columns'])->toBe([]);

    @unlink($path);
});

test('legacy migration sample csvs share member identifiers across files', function () {
    $memberNumbers = array_column(LegacyMigrationSampleCsv::memberRows(), 0);
    $memberNames = array_column(LegacyMigrationSampleCsv::memberRows(), 1);

    foreach (LegacyMigrationSampleCsv::loanRows() as $row) {
        [$number, $name] = [$row[2], $row[3]];

        if ($number !== '') {
            expect($number)->toBeIn($memberNumbers);
        } else {
            expect($name)->toBeIn($memberNames);
        }

        $guarantorNumber = $row[11] ?? '';
        $guarantorName = $row[12] ?? '';

        if ($guarantorNumber !== '') {
            expect($guarantorNumber)->toBeIn($memberNumbers);
        }

        if ($guarantorName !== '') {
            expect($guarantorName)->toBeIn($memberNames);
        }
    }

    foreach (LegacyMigrationSampleCsv::paymentRows() as $row) {
        [$number, $name] = [$row[0], $row[1]];

        if ($number !== '') {
            expect($number)->toBeIn($memberNumbers);
        } else {
            expect($name)->toBeIn($memberNames);
        }
    }
});

test('legacy migration preview detects member_number in utf-8 bom csv', function () {
    $path = storage_path('app/legacy-bom-members-preview.csv');

    file_put_contents($path, "\xEF\xBB\xBFmember_number,name,email\n1,Bom Member,bom-member@fund.test\n");

    $preview = app(LegacyMigrationPreviewService::class)->previewMembers($path);

    expect($preview['headers'])->toContain('member_number')
        ->and($preview['row_count'])->toBe(1)
        ->and(collect($preview['warnings'])->contains(
            fn (string $warning): bool => str_contains($warning, 'member_number'),
        ))->toBeFalse();

    @unlink($path);
});

test('legacy migration preview accepts real legacy members export shape', function () {
    $source = base_path('docs/legacy/legacy-members-import-1.csv');

    if (! is_readable($source)) {
        skip('Sample legacy members CSV is not present in docs/legacy.');
    }

    $preview = app(LegacyMigrationPreviewService::class)->previewMembers($source);

    expect($preview['headers'])->toContain('member_number')
        ->and($preview['row_count'])->toBeGreaterThan(0)
        ->and(collect($preview['warnings'])->contains(
            fn (string $warning): bool => str_contains($warning, 'member_number'),
        ))->toBeFalse();
});

test('legacy member import imports all rows when dependents share household contact emails', function () {
    $this->actingAs($this->admin, 'tenant');

    $source = storage_path('app/legacy-migration-shared-email-members.csv');

    AssociativeCsv::write($source, ['member_number', 'name', 'email', 'parent_member_number'], [
        ['1', 'Household Head', 'household.head@fund.test', ''],
        ['2', 'Household Dependent', 'household.head@fund.test', '1'],
    ]);

    $result = app(MemberImportService::class)->import($source, 'password123', '2025-12-31');

    expect($result['created'])->toBe(2)
        ->and($result['skipped'])->toBe(0)
        ->and($result['failed'])->toBe(0)
        ->and(Member::query()->count())->toBe(2);

    app(LegacyMigrationOrchestrator::class)->assertAllCsvMembersPresent($source, $result);

    @unlink($source);
});

test('legacy migration sample csvs pass preview validation', function () {
    $membersPath = storage_path('app/legacy-migration-sample-members.csv');
    $loansPath = storage_path('app/legacy-migration-sample-loans.csv');
    $paymentsPath = storage_path('app/legacy-migration-sample-payments.csv');

    AssociativeCsv::write($membersPath, LegacyMigrationSampleCsv::memberHeaders(), LegacyMigrationSampleCsv::memberRows());
    AssociativeCsv::write($loansPath, LegacyMigrationSampleCsv::loanHeaders(), LegacyMigrationSampleCsv::loanRows());
    AssociativeCsv::write($paymentsPath, LegacyMigrationSampleCsv::paymentHeaders(), LegacyMigrationSampleCsv::paymentRows());

    $preview = app(LegacyMigrationPreviewService::class);

    expect($preview->previewMembers($membersPath))
        ->missing_columns->toBe([])
        ->row_count->toBe(3)
        ->and($preview->previewLoans($loansPath))
        ->missing_columns->toBe([])
        ->row_count->toBe(3)
        ->and($preview->previewPayments($paymentsPath))
        ->missing_columns->toBe([])
        ->row_count->toBe(4);

    @unlink($membersPath);
    @unlink($loansPath);
    @unlink($paymentsPath);
});

test('legacy migration orchestrator dry run validates members csv', function () {
    $path = storage_path('app/legacy-migration-test-members.csv');

    AssociativeCsv::write($path, ['member_number', 'name', 'cutoff_cash_balance', 'cutoff_fund_balance'], [
        ['member_number' => 'LEG-0001', 'name' => 'Legacy Member', 'cutoff_cash_balance' => '100', 'cutoff_fund_balance' => '500'],
    ]);

    $result = app(LegacyMigrationOrchestrator::class)->run([
        'cutoff_date' => '2025-12-31',
        'default_password' => 'password123',
        'members_path' => $path,
        'strategy' => 'snapshot',
    ], dryRun: true);

    expect($result['members']['created'])->toBe(1);

    @unlink($path);
});

test('legacy migration orchestrator dry run reports classified payment stats', function () {
    $membersPath = storage_path('app/dry-run-members.csv');
    $classifiedPath = storage_path('app/dry-run-classified.csv');

    AssociativeCsv::write($membersPath, ['member_number', 'name', 'email'], [
        ['1', 'Dry Run Member', 'dry-run@fund.test'],
    ]);
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'loan_number',
        'period',
        'notes',
    ], [
        ['', '1', '2025-10-01', '1000', 'contribution', '', '2025-10', ''],
        ['', '1', '2025-11-01', '500', 'loan_repayment', '1', '', ''],
        ['', '1', '2024-06-01', '500', 'ignore', '', '2024-06', ''],
    ]);

    $result = app(LegacyMigrationOrchestrator::class)->run([
        'cutoff_date' => '2025-12-31',
        'default_password' => 'password123',
        'members_path' => $membersPath,
        'classified_payments_path' => $classifiedPath,
        'strategy' => 'historical',
    ], dryRun: true);

    expect($result['payments']['contributions'])->toBe(1)
        ->and($result['payments']['loan_repayments'])->toBe(1)
        ->and($result['payments']['ignored'])->toBe(1)
        ->and($result['payments']['failed'])->toBe(0);

    @unlink($membersPath);
    @unlink($classifiedPath);
});

test('legacy migration orchestrator dry run classifies payments when classified csv is missing', function () {
    $membersPath = storage_path('app/dry-run-inline-members.csv');
    $loansPath = storage_path('app/dry-run-inline-loans.csv');
    $paymentsPath = storage_path('app/dry-run-inline-payments.csv');

    AssociativeCsv::write($membersPath, ['member_number', 'name', 'email', 'monthly_contribution_amount'], [
        ['1', 'Inline Dry Run Member', '', '1000'],
    ]);
    AssociativeCsv::write($loansPath, ['member_number', 'amount_approved', 'disbursed_at', 'loan_status'], [
        ['1', '12000', '2/25/2016', 'active'],
    ]);
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], [
        ['1', '8/10/2014', '1000'],
        ['1', '5/11/2016', '1000'],
    ]);

    $result = app(LegacyMigrationOrchestrator::class)->run([
        'cutoff_date' => '2025-12-31',
        'default_password' => 'password123',
        'members_path' => $membersPath,
        'loans_path' => $loansPath,
        'payments_path' => $paymentsPath,
        'strategy' => 'historical',
    ], dryRun: true);

    expect($result['payments']['contributions'])->toBe(1)
        ->and($result['payments']['loan_repayments'])->toBe(1);

    @unlink($membersPath);
    @unlink($loansPath);
    @unlink($paymentsPath);
});

test('legacy migration loan import uses split funding strategy when csv omits portions', function () {
    $this->actingAs($this->admin, 'tenant');

    LoanSettings::save(['member_funding_split_pct' => 40]);
    LegacyMigrationFundingStrategySettings::saveFundingStrategy(LoanFundingStrategy::SPLIT_PERCENTAGE);

    $membersPath = storage_path('app/legacy-funding-members.csv');
    $loansPath = storage_path('app/legacy-funding-loans.csv');

    AssociativeCsv::write($membersPath, [
        'member_number',
        'name',
        'email',
        'monthly_contribution_amount',
        'cutoff_cash_balance',
        'cutoff_fund_balance',
    ], [
        ['FUND-1', 'Funding Strategy Member', 'funding-strategy@fund.test', '1000', '0', '8000'],
    ]);
    AssociativeCsv::write($loansPath, ['loan_status', 'member_number', 'amount_approved', 'disbursed_at', 'installments_count'], [
        ['active', 'FUND-1', '10000', '2024-06-01', '10'],
    ]);

    app(LegacyMigrationOrchestrator::class)->importMembersAndLoans([
        'default_password' => 'password123',
        'members_path' => $membersPath,
        'loans_path' => $loansPath,
        'loan_funding_strategy' => LoanFundingStrategy::SPLIT_PERCENTAGE,
    ], '2025-12-31');

    $loan = Loan::query()->whereHas('member', fn ($query) => $query->where('member_number', 'FUND-1'))->firstOrFail();

    expect((float) $loan->member_portion)->toBe(4000.0)
        ->and((float) $loan->master_portion)->toBe(6000.0)
        ->and($loan->funding_strategy)->toBe(LoanFundingStrategy::SPLIT_PERCENTAGE);

    @unlink($membersPath);
    @unlink($loansPath);
});

test('legacy migration loan import uses member fund topup strategy when csv omits portions', function () {
    $this->actingAs($this->admin, 'tenant');

    Account::masterCash()->update(['balance' => 200_000]);
    Account::masterFund()->update(['balance' => 200_000]);

    $member = Member::create([
        'member_number' => 'TOP-1',
        'name' => 'Topup Strategy Member',
        'email' => 'topup-strategy@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);
    AccountingService::withoutMemberCashCollection(
        fn () => app(AccountingService::class)->credit($member->fundAccount, 3000, 'Seed fund'),
    );
    expect((float) $member->fresh()->fundAccount->balance)->toBe(3000.0);

    $loansPath = storage_path('app/legacy-topup-loans.csv');
    AssociativeCsv::write($loansPath, ['loan_status', 'member_number', 'amount_approved', 'disbursed_at', 'installments_count'], [
        ['active', 'TOP-1', '10000', '2024-06-01', '10'],
    ]);

    app(LoanImportService::class)->import(
        $loansPath,
        1,
        LoanFundingStrategy::MEMBER_FUND_TOPUP,
    );

    $loan = Loan::query()->where('member_id', $member->id)->firstOrFail();

    expect((float) $loan->member_portion)->toBe(3000.0)
        ->and((float) $loan->master_portion)->toBe(7000.0)
        ->and($loan->funding_strategy)->toBe(LoanFundingStrategy::MEMBER_FUND_TOPUP);

    @unlink($loansPath);
});

test('legacy migration loan import replays historical contributions for member fund topup', function () {
    $membersPath = base_path('docs/legacy/legacy-members-import.csv');
    $loansPath = base_path('docs/legacy/legacy-loans-import.csv');
    $paymentsPath = base_path('docs/legacy/legacy-payments-import.csv');

    if (! is_readable($membersPath) || ! is_readable($loansPath) || ! is_readable($paymentsPath)) {
        skip('Legacy sample CSVs are not present in docs/legacy.');
    }

    $this->actingAs($this->admin, 'tenant');

    LegacyMigrationDateFormatSettings::saveSlashDateFormat(LegacyMigrationDateFormatSettings::SLASH_EUROPEAN);

    Account::masterCash()->update(['balance' => 5_000_000]);
    Account::masterFund()->update(['balance' => 5_000_000]);

    app(LegacyMigrationOrchestrator::class)->importMembersAndLoans([
        'default_password' => 'password123',
        'members_path' => $membersPath,
        'loans_path' => $loansPath,
        'payments_path' => $paymentsPath,
        'classified_payments_path' => $paymentsPath,
        'strategy' => 'historical',
        'loan_funding_strategy' => LoanFundingStrategy::MEMBER_FUND_TOPUP,
    ], '2025-11-05');

    $member = Member::query()->where('member_number', '23')->firstOrFail();
    $loan = Loan::query()
        ->where('member_id', $member->id)
        ->whereDate('disbursed_at', '2017-06-14')
        ->where('amount_approved', 80000)
        ->firstOrFail();

    expect((float) $loan->member_portion)->toBe(40000.0)
        ->and((float) $loan->master_portion)->toBe(40000.0)
        ->and($loan->funding_strategy)->toBe(LoanFundingStrategy::MEMBER_FUND_TOPUP);
});

test('legacy migration loan funding simulator respects payment date format and disbursement debits', function () {
    $this->actingAs($this->admin, 'tenant');

    LegacyMigrationDateFormatSettings::saveSlashDateFormat(LegacyMigrationDateFormatSettings::SLASH_EUROPEAN);

    $member = Member::create([
        'member_number' => 'SIM-1',
        'name' => 'Simulator Member',
        'email' => 'simulator@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(5),
        'status' => 'active',
    ]);

    $paymentsPath = storage_path('app/legacy-simulator-payments.csv');
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount', 'payment_type'], [
        ['SIM-1', '01/01/2016', '5000', 'contribution'],
        ['SIM-1', '01/06/2016', '3000', 'contribution'],
        ['SIM-1', '15/03/2017', '2000', 'loan_repayment'],
        ['SIM-1', '01/05/2017', '1000', 'contribution'],
    ]);

    $simulator = LegacyMigrationLoanFundingSimulator::fromPaymentsCsv($paymentsPath);
    $disbursementDate = Carbon::parse('2017-06-01');

    expect($simulator->fundBalanceBeforeDisbursement($member, $disbursementDate))->toBe(9000.0);

    $simulator->recordDisbursement($member, $disbursementDate, 4000.0);

    expect($simulator->fundBalanceBeforeDisbursement($member, Carbon::parse('2017-07-01')))->toBe(5000.0);

    @unlink($paymentsPath);
});

test('legacy migration loan import can skip settlement threshold', function () {
    $this->actingAs($this->admin, 'tenant');

    LoanSettings::save(['settlement_threshold_pct' => 0.16]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 1000)->first()
        ?? LoanTier::create([
            'tier_number' => 91,
            'label' => 'Skip threshold tier',
            'min_amount' => 1000,
            'max_amount' => 100_000,
            'min_monthly_installment' => 1000,
            'is_active' => true,
        ]);

    $membersPath = storage_path('app/legacy-skip-threshold-members.csv');
    $loansPath = storage_path('app/legacy-skip-threshold-loans.csv');

    AssociativeCsv::write($membersPath, [
        'member_number',
        'name',
        'email',
        'monthly_contribution_amount',
        'cutoff_cash_balance',
        'cutoff_fund_balance',
    ], [
        ['SKIP-TH', 'Skip Threshold Member', 'skip-threshold@fund.test', '1000', '0', '0'],
    ]);
    AssociativeCsv::write($loansPath, [
        'loan_status',
        'member_number',
        'amount_approved',
        'member_portion',
        'master_portion',
        'disbursed_at',
        'loan_tier_number',
    ], [
        ['active', 'SKIP-TH', '10000', '5000', '5000', '2024-06-01', (string) $loanTier->tier_number],
    ]);

    app(LegacyMigrationOrchestrator::class)->importMembersAndLoans([
        'default_password' => 'password123',
        'members_path' => $membersPath,
        'loans_path' => $loansPath,
        'skip_settlement_threshold' => true,
    ], '2025-12-31');

    $loan = Loan::query()->whereHas('member', fn ($query) => $query->where('member_number', 'SKIP-TH'))->firstOrFail();

    expect((float) $loan->settlement_threshold)->toBe(0.0)
        ->and($loan->installments_count)->toBe(5);

    @unlink($membersPath);
    @unlink($loansPath);
});

test('legacy migration orchestrator imports payments without contribution notifications', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'LEG-PAY-IMPORT',
        'name' => 'Payment Import Member',
        'email' => 'payment-import@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    AccountingService::withoutMemberCashCollection(
        fn () => app(AccountingService::class)->creditMemberCashWithMasterMirror(
            $member->cashAccount,
            5000,
            'Seed',
            '',
            null,
            now(),
            $member->id,
        ),
    );
    AccountingService::withoutMemberCashCollection(
        fn () => app(AccountingService::class)->creditMemberFundWithMasterMirror(
            $member->fundAccount,
            100_000,
            'Seed fund',
            '',
            null,
            now(),
            $member->id,
        ),
    );
    Account::masterFund()?->update(['balance' => 100_000]);

    $classifiedPath = storage_path('app/legacy-payment-import-test.csv');
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'loan_number',
        'period',
        'notes',
    ], [
        ['', 'LEG-PAY-IMPORT', '2025-10-01', '1000', 'contribution', '', '2025-10', ''],
    ]);

    $result = ContributionService::withoutPostedNotifications(
        fn (): array => app(LegacyPaymentImportService::class)->import($classifiedPath),
    );

    $member = $member->fresh();
    $masterCash = Account::masterCash()?->fresh();

    expect($result['contributions'])->toBe(1)
        ->and($result['failed'])->toBe(0)
        ->and((float) $member->cashAccount->balance)->toBe(5000.0)
        ->and((float) $member->fundAccount->balance)->toBe(101_000.0)
        ->and((float) ($masterCash?->balance ?? 0))->toBe(5000.0);

    $contribution = Contribution::query()->where('member_id', $member->id)->firstOrFail();
    $transactionDates = $contribution->transactions()
        ->pluck('transacted_at')
        ->filter()
        ->map(fn ($date) => Carbon::parse((string) $date)->toDateString())
        ->unique()
        ->values()
        ->all();
    expect($transactionDates)->toBe(['2025-10-01']);

    Notification::assertNothingSent();

    @unlink($classifiedPath);
});

test('legacy migration historical run rebuilds chronological ledger running balances', function () {
    $this->actingAs($this->admin, 'tenant');

    $membersPath = storage_path('app/historical-running-balance-members.csv');
    $loansPath = storage_path('app/historical-running-balance-loans.csv');
    $classifiedPath = storage_path('app/historical-running-balance-classified.csv');

    AssociativeCsv::write($membersPath, ['member_number', 'name', 'email', 'monthly_contribution_amount'], [
        ['RB-001', 'Running Balance Member', 'running-balance@fund.test', '1000'],
    ]);
    AssociativeCsv::write($loansPath, ['loan_status', 'member_number', 'amount_approved', 'disbursed_at', 'installments_count'], [
        ['active', 'RB-001', '10000', '2024-06-01', '10'],
    ]);
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'loan_number',
        'period',
        'notes',
    ], [
        ['', 'RB-001', '2014-10-01', '500', 'contribution', '', '2014-10', ''],
    ]);

    $result = app(LegacyMigrationOrchestrator::class)->run([
        'default_password' => 'password123',
        'members_path' => $membersPath,
        'loans_path' => $loansPath,
        'classified_payments_path' => $classifiedPath,
        'strategy' => 'historical',
    ]);

    expect($result['members']['created'])->toBe(1);

    $member = Member::query()->where('member_number', 'RB-001')->firstOrFail();
    $fundAccount = $member->fundAccount()->firstOrFail();
    $firstFundLine = Transaction::query()
        ->where('account_id', $fundAccount->id)
        ->orderBy('transacted_at')
        ->orderBy('id')
        ->firstOrFail();

    expect((float) $firstFundLine->balance_after)->toBe(500.0);

    @unlink($membersPath);
    @unlink($loansPath);
    @unlink($classifiedPath);
});

test('legacy payment import posts historical contributions even when member has active loan grace', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'LEG-LOAN-GRACE',
        'name' => 'Legacy Loan Grace Member',
        'email' => 'legacy-loan-grace@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 100_000]);
    Account::masterFund()->update(['balance' => 100_000]);
    AccountingService::withoutMemberCashCollection(
        fn () => app(AccountingService::class)->credit($member->cashAccount, 5000, 'Seed'),
    );

    Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'has_grace_cycle' => true,
        'first_repayment_month' => 4,
        'first_repayment_year' => 2016,
        'disbursed_at' => '2016-02-25',
        'purpose' => 'Legacy loan',
    ]);

    expect($member->fresh()->isExemptFromContributions(10, 2014))->toBeTrue();

    $classifiedPath = storage_path('app/legacy-payment-loan-grace-test.csv');
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'loan_number',
        'period',
        'notes',
    ], [
        ['', 'LEG-LOAN-GRACE', '2014-10-08', '1000', 'contribution', '', '2014-10', ''],
    ]);

    $result = ContributionService::withoutPostedNotifications(
        fn (): array => app(LegacyPaymentImportService::class)->import($classifiedPath),
    );

    expect($result['contributions'])->toBe(1)
        ->and($result['ignored'])->toBe(0)
        ->and($result['failed'])->toBe(0)
        ->and(Contribution::query()->where('member_id', $member->id)->count())->toBe(1);

    @unlink($classifiedPath);
});

test('legacy payment import resolves member by member_number when household email is shared', function () {
    Notification::fake();

    $head = Member::create([
        'member_number' => 'PAY-HEAD',
        'name' => 'Payment Head',
        'email' => 'shared.payment@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $dependent = Member::create([
        'member_number' => 'PAY-DEP',
        'name' => 'Payment Dependent',
        'email' => 'shared.payment@fund.test',
        'parent_member_id' => $head->id,
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(8),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($head);
    app(AccountingService::class)->createMemberAccounts($dependent);
    Account::masterCash()->update(['balance' => 100_000]);
    Account::masterFund()->update(['balance' => 100_000]);
    AccountingService::withoutMemberCashCollection(
        fn () => app(AccountingService::class)->credit($dependent->cashAccount, 5000, 'Seed'),
    );

    $classifiedPath = storage_path('app/legacy-payment-member-number-test.csv');
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'loan_number',
        'period',
        'notes',
    ], [
        ['shared.payment@fund.test', 'PAY-DEP', '2014-10-08', '500', 'contribution', '', '2014-10', ''],
    ]);

    $result = app(LegacyPaymentImportService::class)->import($classifiedPath);

    expect($result['contributions'])->toBe(1)
        ->and($result['ignored'])->toBe(0)
        ->and($result['failed'])->toBe(0)
        ->and(Contribution::query()->where('member_id', $dependent->id)->count())->toBe(1)
        ->and(Contribution::query()->where('member_id', $head->id)->count())->toBe(0);

    @unlink($classifiedPath);
});

test('legacy payment import posts split classified rows that share the same source note', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'SPLIT-NOTE',
        'name' => 'Split Note Member',
        'email' => 'split-note@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 100_000]);
    Account::masterFund()->update(['balance' => 100_000]);
    AccountingService::withoutMemberCashCollection(
        fn() => app(AccountingService::class)->credit($member->cashAccount, 10_000, 'Seed'),
    );

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1500,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2023-01-01',
        'purpose' => 'Legacy loan',
    ]);

    $sharedNotes = 'Legacy source payment row 5071';

    $classifiedPath = storage_path('app/legacy-payment-split-note-test.csv');
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'loan_number',
        'period',
        'notes',
    ], [
        ['', 'SPLIT-NOTE', '2023-07-01', '1500', 'loan_repayment', (string) $loan->id, '', $sharedNotes],
        ['', 'SPLIT-NOTE', '2023-07-01', '1000', 'contribution', '', '2023-06', $sharedNotes],
    ]);

    $result = ContributionService::withoutPostedNotifications(
        fn(): array => app(LegacyPaymentImportService::class)->import($classifiedPath),
    );

    expect($result['loan_repayments'])->toBe(1)
        ->and($result['contributions'])->toBe(1)
        ->and($result['failed'])->toBe(0)
        ->and(LoanRepayment::query()->where('loan_id', $loan->id)->count())->toBe(1)
        ->and(Contribution::query()->where('member_id', $member->id)->posted()->count())->toBe(1);

    $contribution = Contribution::query()
        ->where('member_id', $member->id)
        ->where('period', Contribution::periodDate(6, 2023))
        ->firstOrFail();

    expect((float) $contribution->amount)->toBe(1000.0);

    @unlink($classifiedPath);
});

test('legacy migration classify payments writes downloadable classified csv', function () {
    Filament::setCurrentPanel('tenant');

    Storage::disk('local')->put('legacy-migration/working/members.csv', implode("\n", [
        'member_number,name,email,monthly_contribution_amount',
        '1,Classify Member,classify@fund.test,1000',
    ]));
    Storage::disk('local')->put('legacy-migration/working/loans.csv', implode("\n", [
        'member_number,amount_approved,disbursed_at,loan_status',
    ]));
    Storage::disk('local')->put('legacy-migration/working/payments.csv', implode("\n", [
        'member_number,payment_date,amount',
        '1,2025-10-01,1000',
    ]));

    Livewire::actingAs($this->admin, 'tenant')
        ->test(LegacyMigrationPage::class)
        ->fillForm([
            'cutoff_date' => '2025-12-31',
            'default_password' => 'password123',
            'members_csv' => ['legacy-migration/working/members.csv'],
            'loans_csv' => ['legacy-migration/working/loans.csv'],
            'payments_csv' => ['legacy-migration/working/payments.csv'],
        ])
        ->call('importMembers')
        ->assertNotified(__('Members imported'))
        ->call('importLoans')
        ->assertNotified(__('Loans imported'))
        ->call('classifyPayments')
        ->assertNotified(__('Payments classified'))
        ->assertSet('currentStep', 4)
        ->assertSet('classifiedPaymentsReady', true)
        ->assertSet('classificationStats.contributions', 1);

    expect(Storage::disk('local')->exists('legacy-migration/last-classified-payments.csv'))->toBeTrue();

    $tenant = tenant();
    $domain = $tenant->domains()->first()?->domain ?? 'testing.localhost';

    if (! $tenant->domains()->where('domain', 'testing.localhost')->exists()) {
        $tenant->domains()->create(['domain' => 'testing.localhost']);
        $domain = 'testing.localhost';
    }

    $this->actingAs($this->admin, 'tenant')
        ->get('http://'.$domain.route('tenant.admin.legacy-migration.classified-payments-download', [], false))
        ->assertSuccessful()
        ->assertDownload('legacy-payments-classified.csv');

    Storage::disk('local')->delete([
        'legacy-migration/working/members.csv',
        'legacy-migration/working/loans.csv',
        'legacy-migration/working/payments.csv',
        'legacy-migration/last-classified-payments.csv',
    ]);
});

test('legacy migration blocks classify payments until members and loans are imported', function () {
    Filament::setCurrentPanel('tenant');

    Storage::disk('local')->put('legacy-migration/working/members.csv', implode("\n", [
        'member_number,name,email,monthly_contribution_amount',
        '1,Classify Member,classify@fund.test,1000',
    ]));
    Storage::disk('local')->put('legacy-migration/working/loans.csv', implode("\n", [
        'member_number,amount_approved,disbursed_at,loan_status',
    ]));
    Storage::disk('local')->put('legacy-migration/working/payments.csv', implode("\n", [
        'member_number,payment_date,amount',
        '1,2025-10-01,1000',
    ]));

    Livewire::actingAs($this->admin, 'tenant')
        ->test(LegacyMigrationPage::class)
        ->fillForm([
            'cutoff_date' => '2025-12-31',
            'members_csv' => ['legacy-migration/working/members.csv'],
            'loans_csv' => ['legacy-migration/working/loans.csv'],
            'payments_csv' => ['legacy-migration/working/payments.csv'],
        ])
        ->call('classifyPayments')
        ->assertNotified(__('Import loans first'))
        ->assertSet('classifiedPaymentsReady', false);

    Storage::disk('local')->delete([
        'legacy-migration/working/members.csv',
        'legacy-migration/working/loans.csv',
        'legacy-migration/working/payments.csv',
    ]);
});

test('legacy migration can classify payments without default password', function () {
    Filament::setCurrentPanel('tenant');

    Storage::disk('local')->put('legacy-migration/working/members.csv', implode("\n", [
        'member_number,name,email,monthly_contribution_amount',
        '1,Classify Member,classify@fund.test,1000',
    ]));
    Storage::disk('local')->put('legacy-migration/working/loans.csv', implode("\n", [
        'member_number,amount_approved,disbursed_at,loan_status',
    ]));
    Storage::disk('local')->put('legacy-migration/working/payments.csv', implode("\n", [
        'member_number,payment_date,amount',
        '1,2025-10-01,1000',
    ]));

    $this->actingAs($this->admin, 'tenant');

    app(LegacyMigrationOrchestrator::class)->importMembersAndLoans([
        'default_password' => 'password123',
        'members_path' => Storage::disk('local')->path('legacy-migration/working/members.csv'),
        'loans_path' => Storage::disk('local')->path('legacy-migration/working/loans.csv'),
        'grace_cycles' => LegacyMigrationGraceCycleSettings::defaultGraceCycles(),
        'loan_funding_strategy' => LegacyMigrationFundingStrategySettings::defaultFundingStrategy(),
        'skip_settlement_threshold' => false,
    ], '2025-12-31');

    Setting::set('legacy_migration', 'members_imported', '1');
    Setting::set('legacy_migration', 'loans_imported', '1');

    Livewire::actingAs($this->admin, 'tenant')
        ->test(LegacyMigrationPage::class)
        ->set('currentStep', 3)
        ->fillForm([
            'cutoff_date' => '2025-12-31',
            'default_password' => '',
            'members_csv' => ['legacy-migration/working/members.csv'],
            'loans_csv' => ['legacy-migration/working/loans.csv'],
            'payments_csv' => ['legacy-migration/working/payments.csv'],
        ])
        ->call('classifyPayments')
        ->assertSet('classifiedPaymentsReady', true);

    Storage::disk('local')->delete([
        'legacy-migration/working/members.csv',
        'legacy-migration/working/loans.csv',
        'legacy-migration/working/payments.csv',
        'legacy-migration/last-classified-payments.csv',
    ]);
});

test('legacy migration pollClassificationStatus updates results when classification completes', function () {
    Filament::setCurrentPanel('tenant');

    Setting::set('legacy_migration', 'classify_stats', json_encode([
        'contributions' => 4189,
        'future_contributions' => 12,
        'loan_repayments' => 2837,
        'reclassified_as_contribution' => 4,
        'failed' => 0,
    ]));
    Setting::set('legacy_migration', 'classify_errors', json_encode([]));
    Setting::set('legacy_migration', 'classify_status', 'completed');

    Storage::disk('local')->put('legacy-migration/last-classified-payments.csv', "header\n");

    Livewire::actingAs($this->admin, 'tenant')
        ->test(LegacyMigrationPage::class)
        ->set('currentStep', 3)
        ->set('lastKnownClassificationStatus', 'running')
        ->call('pollClassificationStatus')
        ->assertNotified(__('Payments classified'))
        ->assertSet('classificationStats.contributions', 4189)
        ->assertSet('classifiedPaymentsReady', true)
        ->assertSet('classificationRunning', false);

    Storage::disk('local')->delete('legacy-migration/last-classified-payments.csv');
});

test('payment classifier resolves members from members csv before database import', function () {
    $membersPath = storage_path('app/classify-csv-members.csv');
    $paymentsPath = storage_path('app/classify-csv-payments.csv');

    AssociativeCsv::write($membersPath, ['member_number', 'name', 'email', 'monthly_contribution_amount'], [
        ['1', 'Csv Member One', '', '1000'],
    ]);
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], [
        ['1', '2025-10-01', '1000'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
        $membersPath,
    );

    expect($result['stats']['contribution'])->toBe(1)
        ->and($result['rows'][0]['member_number'])->toBe('1');

    @unlink($membersPath);
    @unlink($paymentsPath);
});

test('payment classifier matches legacy payments export to members csv', function () {
    $members = base_path('docs/legacy/legacy-members-import-1.csv');
    $payments = base_path('docs/legacy/legacy-payments-import.csv');
    $loans = base_path('docs/legacy/legacy-loans-import.csv');

    if (! is_readable($members) || ! is_readable($payments)) {
        skip('Legacy sample CSVs are not present in docs/legacy.');
    }

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $payments,
        now()->parse('2025-12-31'),
        $members,
        is_readable($loans) ? $loans : null,
    );

    $loanRepaymentsIn2014 = collect($result['rows'])
        ->filter(fn (array $row): bool => $row['payment_type'] === 'loan_repayment' && str_starts_with($row['payment_date'], '2014'))
        ->count();

    $memberOneAfterLoan = collect($result['rows'])
        ->filter(fn (array $row): bool => $row['member_number'] === '1' && $row['payment_date'] >= '2016-08-01' && $row['payment_date'] <= '2016-10-31')
        ->pluck('payment_type')
        ->unique()
        ->all();

    expect(count($result['rows']))->toBeGreaterThan(0)
        ->and($result['rows'][0]['member_number'])->toBe('1')
        ->and($result['rows'][0]['payment_type'])->toBe('contribution')
        ->and($loanRepaymentsIn2014)->toBe(0)
        ->and($memberOneAfterLoan)->toBe(['loan_repayment'])
        ->and($result['stats']['failed'] ?? 0)->toBeGreaterThanOrEqual(0);
});

test('payment classifier suggests contribution for monthly amount match', function () {
    $member = Member::create([
        'member_number' => 'LEG-001',
        'name' => 'Classifier Member',
        'email' => 'classifier-member@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $path = storage_path('app/legacy-migration-test-payments.csv');

    AssociativeCsv::write($path, ['member_email', 'payment_date', 'amount'], [
        ['member_email' => 'classifier-member@fund.test', 'payment_date' => '2025-10-01', 'amount' => '1000'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile($path, now()->parse('2025-12-31'));

    expect($result['stats']['contribution'])->toBe(1)
        ->and($result['rows'][0]['payment_type'])->toBe('contribution');

    @unlink($path);
});

test('legacy loan repayment target uses fund portion master slice', function () {
    expect(LegacyLoanRepaymentTarget::estimateFromApprovedAmount(100_000))->toBe(50_000.0)
        ->and(LegacyLoanRepaymentTarget::estimateFromApprovedAmount(12_000))->toBe(6_000.0);

    $loan = Loan::create([
        'member_id' => Member::factory()->create()->id,
        'amount' => 150_000,
        'amount_approved' => 150_000,
        'term_months' => 27,
        'master_portion' => 79_500,
        'settlement_threshold' => 0,
        'status' => 'active',
        'disbursed_at' => '2016-02-09',
        'approved_at' => '2016-02-09',
        'applied_at' => '2016-02-09',
    ]);

    expect(LegacyLoanRepaymentTarget::forLoan($loan))->toBe(79_500.0);
});

test('legacy loan repayment target is zero for fully member-funded loans with no settlement', function () {
    $loan = Loan::create([
        'member_id' => Member::factory()->create()->id,
        'amount' => 20_000,
        'amount_approved' => 20_000,
        'term_months' => 0,
        'member_portion' => 20_000,
        'master_portion' => 0,
        'settlement_threshold' => 0,
        'status' => 'active',
        'disbursed_at' => '2016-11-28',
        'approved_at' => '2016-11-28',
        'applied_at' => '2016-11-28',
    ]);

    expect(LegacyLoanRepaymentTarget::forLoan($loan))->toBe(0.0)
        ->and($loan->fullRepaymentThreshold())->toBe(0.0);
});

test('legacy loan installment rebuild expands under-counted settlement schedules for member-funded loans', function () {
    $member = Member::create([
        'member_number' => 'LEG-REBUILD',
        'name' => 'Legacy Rebuild Member',
        'email' => 'legacy-rebuild@fund.test',
        'monthly_contribution_amount' => 2000,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 2000)->first()
        ?? LoanTier::create([
            'tier_number' => 90,
            'label' => 'Rebuild tier',
            'min_amount' => 61_000,
            'max_amount' => 90_000,
            'min_monthly_installment' => 2000,
            'is_active' => true,
        ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 72_000,
        'amount_requested' => 72_000,
        'amount_approved' => 72_000,
        'amount_disbursed' => 72_000,
        'interest_rate' => 0,
        'term_months' => 1,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'member_portion' => 72_000,
        'master_portion' => 0,
        'settlement_threshold' => 0.16,
        'status' => 'active',
        'disbursed_at' => '2024-09-29',
        'approved_at' => '2024-09-29',
        'applied_at' => '2024-09-29',
        'first_repayment_month' => 11,
        'first_repayment_year' => 2024,
        'installments_count' => 1,
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 2000,
        'due_date' => now()->subMonths(1)->toDateString(),
        'status' => 'pending',
    ]);

    $result = app(LegacyImportedLoanInstallmentRebuildService::class)->rebuildImplicitPortionLoans($loan->id);

    $loan->refresh();

    expect($result['loans'])->toBe(1)
        ->and($loan->installments()->count())->toBe(6)
        ->and($loan->installments_count)->toBe(6);
});

test('legacy loan installment rebuild completes fully member-funded loans with no settlement due', function () {
    $member = Member::create([
        'member_number' => 'LEG-FUND-ONLY',
        'name' => 'Fund Only Member',
        'email' => 'legacy-fund-only@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(5),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 10_000,
        'amount_requested' => 10_000,
        'amount_approved' => 10_000,
        'amount_disbursed' => 10_000,
        'interest_rate' => 0,
        'term_months' => 1,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'member_portion' => 10_000,
        'master_portion' => 0,
        'settlement_threshold' => 0,
        'status' => 'active',
        'disbursed_at' => '2023-03-26',
        'approved_at' => '2023-03-26',
        'applied_at' => '2023-03-26',
        'installments_count' => 1,
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 1000,
        'due_date' => '2023-05-05',
        'status' => 'pending',
    ]);

    $result = app(LegacyImportedLoanInstallmentRebuildService::class)->rebuildImplicitPortionLoans($loan->id);

    $loan->refresh();

    expect($result['loans'])->toBe(1)
        ->and($loan->status)->toBe('completed')
        ->and($loan->installments()->count())->toBe(0)
        ->and($loan->installments_count)->toBe(0);
});

test('payment classifier fills suggested loan number from database disbursement date', function () {
    $member = Member::create([
        'member_number' => 'CLS-DB-LOAN',
        'name' => 'Classifier DB Loan Member',
        'email' => 'cls-db-loan@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 12_000,
        'amount_requested' => 12_000,
        'amount_approved' => 12_000,
        'amount_disbursed' => 12_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2016-02-25',
        'approved_at' => '2016-02-25',
        'applied_at' => '2016-02-25',
        'installments_count' => 12,
    ]);

    $paymentsPath = storage_path('app/classify-db-loan-payments.csv');
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], [
        ['CLS-DB-LOAN', '5/11/2016', '1000'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
    );

    expect($result['rows'][0]['payment_type'])->toBe('loan_repayment')
        ->and($result['rows'][0]['loan_number'])->toBe((string) $loan->id);

    @unlink($paymentsPath);
});

test('payment classifier applies loan repayments until fifty fifty plus sixteen percent target is reached', function () {
    $membersPath = storage_path('app/classify-target-members.csv');
    $loansPath = storage_path('app/classify-target-loans.csv');
    $paymentsPath = storage_path('app/classify-target-payments.csv');

    AssociativeCsv::write($membersPath, ['member_number', 'name', 'email', 'monthly_contribution_amount'], [
        ['1', 'Target Member', '', '1000'],
    ]);
    AssociativeCsv::write($loansPath, ['member_number', 'amount_approved', 'disbursed_at', 'loan_status'], [
        ['1', '100000', '2/25/2016', 'active'],
    ]);
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], [
        ['1', '8/10/2014', '1000'],
        ['1', '5/11/2016', '66000'],
        ['1', '5/12/2016', '1000'],
        ['1', '5/1/2017', '3000'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
        $membersPath,
        $loansPath,
    );

    expect($result['rows'][0]['payment_type'])->toBe('contribution')
        ->and($result['rows'][1]['payment_type'])->toBe('loan_repayment')
        ->and($result['rows'][2]['payment_type'])->toBe('contribution')
        ->and($result['rows'][3]['payment_type'])->toBe('contribution')
        ->and($result['rows'][4]['payment_type'])->toBe('contribution')
        ->and((float) $result['rows'][1]['amount'])->toBe(50000.0)
        ->and((float) $result['rows'][2]['amount'])->toBe(16000.0)
        ->and($result['stats']['loan_repayment'])->toBe(1)
        ->and($result['stats']['contribution'])->toBe(4);

    @unlink($membersPath);
    @unlink($loansPath);
    @unlink($paymentsPath);
});

test('payment classifier reclassifies legacy contribution rows inside an active loan repayment window', function () {
    $member = Member::create([
        'member_number' => '23',
        'name' => 'Legacy Contribution Label Member',
        'email' => 'legacy-contribution-label@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 80_000,
        'amount_requested' => 80_000,
        'amount_approved' => 80_000,
        'amount_disbursed' => 80_000,
        'interest_rate' => 0,
        'term_months' => 24,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2017-06-14',
        'approved_at' => '2017-06-14',
        'applied_at' => '2017-06-14',
        'installments_count' => 24,
        'grace_cycles' => 0,
        'has_grace_cycle' => false,
    ]);

    $paymentsPath = storage_path('app/classify-explicit-contribution-payments.csv');
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount', 'payment_type'], [
        ['23', '2017-05-02', '2500', 'contribution'],
        ['23', '2017-07-02', '2000', 'contribution'],
        ['23', '2017-08-28', '2000', 'contribution'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
    );

    expect($result['rows'][0]['payment_type'])->toBe('contribution')
        ->and($result['rows'][1]['payment_type'])->toBe('loan_repayment')
        ->and($result['rows'][1]['loan_number'])->toBe((string) $loan->id)
        ->and($result['rows'][2]['payment_type'])->toBe('loan_repayment')
        ->and($result['stats']['contribution'])->toBe(1)
        ->and($result['stats']['loan_repayment'])->toBe(2);

    @unlink($paymentsPath);
});

test('payment classifier keeps fully member-funded loans as contributions only', function () {
    $member = Member::create([
        'member_number' => 'FUND-ONLY-CLS',
        'name' => 'Fund Only Classifier Member',
        'email' => 'fund-only-cls@fund.test',
        'monthly_contribution_amount' => 2000,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 20_000,
        'amount_requested' => 20_000,
        'amount_approved' => 20_000,
        'amount_disbursed' => 20_000,
        'interest_rate' => 0,
        'term_months' => 0,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'member_portion' => 20_000,
        'master_portion' => 0,
        'settlement_threshold' => 0,
        'status' => 'active',
        'disbursed_at' => '2016-11-28',
        'approved_at' => '2016-11-28',
        'applied_at' => '2016-11-28',
    ]);

    $paymentsPath = storage_path('app/classify-fund-only-payments.csv');
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], [
        ['FUND-ONLY-CLS', '2016-11-28', '2000'],
        ['FUND-ONLY-CLS', '2017-01-05', '2000'],
        ['FUND-ONLY-CLS', '2017-02-04', '2000'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
    );

    expect($result['rows'][0]['payment_type'])->toBe('contribution')
        ->and($result['rows'][1]['payment_type'])->toBe('contribution')
        ->and($result['rows'][2]['payment_type'])->toBe('contribution')
        ->and($result['stats']['loan_repayment'])->toBe(0)
        ->and($result['stats']['contribution'])->toBe(3);

    @unlink($paymentsPath);
});

test('payment classifier prefers database fund portions over loans csv estimates for imported members', function () {
    $member = Member::create([
        'member_number' => '49',
        'name' => 'Fund Only CSV Override Member',
        'email' => 'fund-only-csv-override@fund.test',
        'monthly_contribution_amount' => 2000,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    Loan::create([
        'member_id' => $member->id,
        'amount' => 20_000,
        'amount_requested' => 20_000,
        'amount_approved' => 20_000,
        'amount_disbursed' => 20_000,
        'interest_rate' => 0,
        'term_months' => 0,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'member_portion' => 20_000,
        'master_portion' => 0,
        'settlement_threshold' => 0,
        'status' => 'active',
        'disbursed_at' => '2016-11-23',
        'approved_at' => '2016-11-23',
        'applied_at' => '2016-11-23',
    ]);

    $loansPath = storage_path('app/classify-fund-only-loans.csv');
    AssociativeCsv::write($loansPath, ['legacy_loan_id', 'member_number', 'amount_approved', 'disbursed_at'], [
        ['28', '49', '20000', '11/23/2016'],
    ]);

    $paymentsPath = storage_path('app/classify-fund-only-with-loans-csv-payments.csv');
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], [
        ['49', '2016-11-23', '2000'],
        ['49', '2017-01-05', '2000'],
        ['49', '2017-02-04', '2000'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
        null,
        $loansPath,
    );

    expect($result['rows'][0]['payment_type'])->toBe('contribution')
        ->and($result['rows'][1]['payment_type'])->toBe('contribution')
        ->and($result['rows'][2]['payment_type'])->toBe('contribution')
        ->and($result['stats']['loan_repayment'])->toBe(0)
        ->and($result['stats']['contribution'])->toBe(3);

    @unlink($loansPath);
    @unlink($paymentsPath);
});

test('payment blueprint classification ignores existing database loan repayments for fund-only loans', function () {
    $member = Member::create([
        'member_number' => 'BLUEPRINT-49',
        'name' => 'Blueprint Fund Only Member',
        'email' => 'blueprint-fund-only@fund.test',
        'monthly_contribution_amount' => 2000,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 20_000,
        'amount_requested' => 20_000,
        'amount_approved' => 20_000,
        'amount_disbursed' => 20_000,
        'interest_rate' => 0,
        'term_months' => 0,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'member_portion' => 20_000,
        'master_portion' => 0,
        'settlement_threshold' => 0,
        'status' => 'completed',
        'disbursed_at' => '2016-11-23',
        'approved_at' => '2016-11-23',
        'applied_at' => '2016-11-23',
    ]);

    LoanRepayment::create([
        'loan_id' => $loan->id,
        'amount' => 2000,
        'paid_at' => '2017-01-05',
        'notes' => 'legacy-import:BLUEPRINT-49||5-Jan-17|2000||',
    ]);

    $loansPath = storage_path('app/blueprint-fund-only-loans.csv');
    AssociativeCsv::write($loansPath, ['legacy_loan_id', 'member_number', 'amount_approved', 'disbursed_at'], [
        ['28', 'BLUEPRINT-49', '20000', '11/23/2016'],
    ]);

    $paymentsPath = storage_path('app/blueprint-fund-only-payments.csv');
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], [
        ['BLUEPRINT-49', '2016-11-23', '2000'],
        ['BLUEPRINT-49', '2017-01-05', '2000'],
        ['BLUEPRINT-49', '2017-02-04', '2000'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
        null,
        $loansPath,
    );

    expect($result['stats']['loan_repayment'])->toBe(0)
        ->and($result['stats']['contribution'])->toBe(3)
        ->and(collect($result['rows'])->pluck('loan_number')->filter()->isEmpty())->toBeTrue();

    @unlink($loansPath);
    @unlink($paymentsPath);
});

test('payment blueprint routes duplicate contribution months to the next free period', function () {
    $member = Member::create([
        'member_number' => 'CLS-DB-1',
        'name' => 'Classify DB Match Member',
        'email' => 'classify-db-match@fund.test',
        'monthly_contribution_amount' => 2000,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $paymentsPath = storage_path('app/classify-db-match-payments.csv');
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], [
        ['CLS-DB-1', '2017-01-05', '2000'],
        ['CLS-DB-1', '2017-01-15', '2000'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
    );

    expect($result['rows'][0]['period'])->toBe('2016-12')
        ->and($result['rows'][1]['period'])->toBe('2017-01')
        ->and($result['stats']['loan_repayment'])->toBe(0);

    @unlink($paymentsPath);
});

test('payment blueprint maps contribution periods to contribution cycles', function () {
    $member = Member::create([
        'member_number' => 'CLS-PERIOD-SEQ',
        'name' => 'Period Sequence Member',
        'email' => 'period-seq@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => '2017-01-01',
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 2000)->first()
        ?? LoanTier::create([
            'tier_number' => 198,
            'label' => 'Period sequence tier',
            'min_amount' => 20_000,
            'max_amount' => 50_000,
            'min_monthly_installment' => 2000,
            'is_active' => true,
        ]);

    Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 20_000,
        'amount_requested' => 20_000,
        'amount_approved' => 20_000,
        'amount_disbursed' => 20_000,
        'member_portion' => 16_000,
        'master_portion' => 4_000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2017-03-01',
        'approved_at' => '2017-03-01',
        'applied_at' => '2017-03-01',
        'installments_count' => 10,
        'grace_cycles' => 0,
        'has_grace_cycle' => false,
    ]);

    $paymentsPath = storage_path('app/classify-period-sequence-payments.csv');
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], [
        ['CLS-PERIOD-SEQ', '2017-01-15', '1000'],
        ['CLS-PERIOD-SEQ', '2017-02-15', '1000'],
        ['CLS-PERIOD-SEQ', '2017-03-15', '2000'],
        ['CLS-PERIOD-SEQ', '2017-04-15', '2000'],
        ['CLS-PERIOD-SEQ', '2017-07-15', '1000'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
    );

    $rowsByDate = collect($result['rows'])->keyBy('payment_date');

    expect($rowsByDate['2017-01-15']['payment_type'])->toBe('contribution')
        ->and($rowsByDate['2017-01-15']['period'])->toBe('2017-01')
        ->and($rowsByDate['2017-02-15']['payment_type'])->toBe('contribution')
        ->and($rowsByDate['2017-02-15']['period'])->toBe('2017-02')
        ->and($rowsByDate['2017-03-15']['payment_type'])->toBe('loan_repayment')
        ->and($rowsByDate['2017-04-15']['payment_type'])->toBe('loan_repayment')
        ->and($rowsByDate['2017-07-15']['payment_type'])->toBe('contribution')
        ->and($rowsByDate['2017-07-15']['period'])->toBe('2017-07');

    @unlink($paymentsPath);
});

test('payment classifier treats below minimum installment payments as contributions only at repayment cycle start', function () {
    $member = Member::create([
        'member_number' => '23',
        'name' => 'Below EMI Member',
        'email' => 'below-emi-member@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 2500)->first()
        ?? LoanTier::create([
            'tier_number' => 95,
            'label' => 'Below EMI tier',
            'min_amount' => 50_000,
            'max_amount' => 100_000,
            'min_monthly_installment' => 2500,
            'is_active' => true,
        ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 80_000,
        'amount_requested' => 80_000,
        'amount_approved' => 80_000,
        'amount_disbursed' => 80_000,
        'interest_rate' => 0,
        'term_months' => 24,
        'monthly_repayment' => 2500,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2021-08-28',
        'approved_at' => '2021-08-28',
        'applied_at' => '2021-08-28',
        'installments_count' => 24,
        'grace_cycles' => 0,
        'has_grace_cycle' => false,
    ]);

    $paymentsPath = storage_path('app/classify-below-emi-payments.csv');
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount', 'payment_type'], [
        ['23', '2021-08-28', '1000', 'loan_repayment'],
        ['23', '2021-09-28', '2500', 'loan_repayment'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
    );

    expect($result['rows'][0]['payment_type'])->toBe('loan_repayment')
        ->and($result['rows'][0]['loan_number'])->toBe((string) $loan->id)
        ->and($result['rows'][1]['payment_type'])->toBe('loan_repayment')
        ->and($result['rows'][1]['loan_number'])->toBe((string) $loan->id)
        ->and($result['stats']['contribution'])->toBe(0)
        ->and($result['stats']['loan_repayment'])->toBe(2);

    @unlink($paymentsPath);
});

test('payment classifier classifies legacy loan repayments from disbursement regardless of grace cycles', function () {
    LegacyMigrationGraceCycleSettings::saveGraceCycles(1);

    $membersPath = storage_path('app/classify-grace-members.csv');
    $loansPath = storage_path('app/classify-grace-loans.csv');
    $paymentsPath = storage_path('app/classify-grace-payments.csv');

    AssociativeCsv::write($membersPath, ['member_number', 'name', 'email', 'monthly_contribution_amount'], [
        ['GRACE-1', 'Grace Member', 'grace-member@fund.test', '1000'],
    ]);
    AssociativeCsv::write($loansPath, ['member_number', 'amount_approved', 'disbursed_at', 'loan_status'], [
        ['GRACE-1', '12000', '2025-01-15', 'active'],
    ]);
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], [
        ['GRACE-1', '2025-02-01', '1000'],
        ['GRACE-1', '2025-04-01', '1000'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
        $membersPath,
        $loansPath,
        1,
    );

    expect($result['rows'][0]['payment_type'])->toBe('loan_repayment')
        ->and($result['rows'][1]['payment_type'])->toBe('loan_repayment')
        ->and($result['stats']['contribution'])->toBe(0)
        ->and($result['stats']['loan_repayment'])->toBe(2);

    LegacyMigrationGraceCycleSettings::saveGraceCycles(0);

    $zeroGraceResult = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
        $membersPath,
        $loansPath,
        0,
    );

    expect($zeroGraceResult['rows'][0]['payment_type'])->toBe('loan_repayment')
        ->and($zeroGraceResult['stats']['loan_repayment'])->toBe(2);

    @unlink($membersPath);
    @unlink($loansPath);
    @unlink($paymentsPath);
});

test('payment classifier treats payments after disbursement as loan repayments when grace cycles are zero', function () {
    $member = Member::create([
        'member_number' => '58',
        'name' => 'Legacy Jul Window Member',
        'email' => 'legacy-jul-window@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => '2019-01-04',
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 1000)->first()
        ?? LoanTier::create([
            'tier_number' => 96,
            'label' => 'Jul window tier',
            'min_amount' => 10_000,
            'max_amount' => 30_000,
            'min_monthly_installment' => 1000,
            'is_active' => true,
        ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 20_000,
        'amount_requested' => 20_000,
        'amount_approved' => 20_000,
        'amount_disbursed' => 20_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2020-07-10',
        'approved_at' => '2020-07-10',
        'applied_at' => '2020-07-10',
        'installments_count' => 12,
        'grace_cycles' => 0,
        'has_grace_cycle' => false,
        'first_repayment_month' => 8,
        'first_repayment_year' => 2020,
    ]);

    $paymentsPath = storage_path('app/classify-jul-window-payments.csv');
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount', 'payment_type'], [
        ['58', '2020-07-29', '1000', 'contribution'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
    );

    expect($result['rows'][0]['payment_type'])->toBe('loan_repayment')
        ->and($result['rows'][0]['loan_number'])->toBe((string) $loan->id)
        ->and($result['stats']['loan_repayment'])->toBe(1)
        ->and($result['stats']['contribution'])->toBe(0);

    @unlink($paymentsPath);
});

test('payment classifier accepts below minimum installment payments after repayment cycle has started', function () {
    $member = Member::create([
        'member_number' => '23',
        'name' => 'Partial EMI Member',
        'email' => 'partial-emi-member@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 2500)->first()
        ?? LoanTier::create([
            'tier_number' => 97,
            'label' => 'Partial EMI tier',
            'min_amount' => 50_000,
            'max_amount' => 100_000,
            'min_monthly_installment' => 2500,
            'is_active' => true,
        ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 80_000,
        'amount_requested' => 80_000,
        'amount_approved' => 80_000,
        'amount_disbursed' => 80_000,
        'interest_rate' => 0,
        'term_months' => 24,
        'monthly_repayment' => 2500,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2021-08-28',
        'approved_at' => '2021-08-28',
        'applied_at' => '2021-08-28',
        'installments_count' => 24,
        'grace_cycles' => 0,
        'has_grace_cycle' => false,
    ]);

    $paymentsPath = storage_path('app/classify-partial-emi-payments.csv');
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount', 'payment_type'], [
        ['23', '2021-09-28', '2500', 'loan_repayment'],
        ['23', '2021-10-15', '1000', 'loan_repayment'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
    );

    expect($result['rows'][0]['payment_type'])->toBe('loan_repayment')
        ->and($result['rows'][0]['loan_number'])->toBe((string) $loan->id)
        ->and($result['rows'][1]['payment_type'])->toBe('loan_repayment')
        ->and($result['rows'][1]['loan_number'])->toBe((string) $loan->id)
        ->and($result['stats']['contribution'])->toBe(0)
        ->and($result['stats']['loan_repayment'])->toBe(2);

    @unlink($paymentsPath);
});

test('payment classifier treats monthly contribution amount as loan repayment at cycle start when below tier EMI', function () {
    $member = Member::create([
        'member_number' => 'LEG-MONTHLY-EMI',
        'name' => 'Monthly EMI Member',
        'email' => 'monthly-emi-member@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 1000)->first()
        ?? LoanTier::create([
            'tier_number' => 96,
            'label' => 'Monthly EMI tier',
            'min_amount' => 1_000,
            'max_amount' => 10_000,
            'min_monthly_installment' => 1000,
            'is_active' => true,
        ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 5_000,
        'amount_requested' => 5_000,
        'amount_approved' => 5_000,
        'amount_disbursed' => 5_000,
        'interest_rate' => 0,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2023-10-29',
        'approved_at' => '2023-10-29',
        'applied_at' => '2023-10-29',
        'installments_count' => 6,
    ]);

    $paymentsPath = storage_path('app/classify-monthly-emi-payments.csv');
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount', 'payment_type'], [
        ['LEG-MONTHLY-EMI', '2023-12-01', '500', ''],
        ['LEG-MONTHLY-EMI', '2024-01-01', '500', ''],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
    );

    expect($result['rows'][0]['payment_type'])->toBe('loan_repayment')
        ->and($result['rows'][0]['loan_number'])->toBe((string) $loan->id)
        ->and($result['rows'][1]['payment_type'])->toBe('loan_repayment')
        ->and($result['rows'][1]['loan_number'])->toBe((string) $loan->id)
        ->and($result['stats']['contribution'])->toBe(0)
        ->and($result['stats']['loan_repayment'])->toBe(2);

    @unlink($paymentsPath);
});

test('legacy misclassified contribution repair converts monthly payments inside loan windows', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'LEG-REPAIR-31',
        'name' => 'Repair Member',
        'email' => 'repair-31@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 1000)->first()
        ?? LoanTier::create([
            'tier_number' => 102,
            'label' => 'Repair tier',
            'min_amount' => 1_000,
            'max_amount' => 10_000,
            'min_monthly_installment' => 1000,
            'is_active' => true,
        ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 5_000,
        'amount_requested' => 5_000,
        'amount_approved' => 5_000,
        'amount_disbursed' => 5_000,
        'interest_rate' => 0,
        'term_months' => 6,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2023-10-29',
        'approved_at' => '2023-10-29',
        'applied_at' => '2023-10-29',
        'installments_count' => 6,
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 500_000]);
    Account::masterFund()->update(['balance' => 500_000]);

    $importService = app(LegacyPaymentImportService::class);

    foreach (['2023-12-01', '2024-01-01'] as $paymentDate) {
        $postedAt = Carbon::parse($paymentDate);
        $importService->postLegacyContributionForRepair(
            $member,
            (int) $postedAt->month,
            (int) $postedAt->year,
            500,
            $postedAt,
            __('Misclassified legacy payment'),
        );
    }

    expect(Contribution::query()->where('member_id', $member->id)->where('status', 'posted')->count())->toBe(2)
        ->and(LoanRepayment::query()->where('loan_id', $loan->id)->count())->toBe(0);

    $repair = app(LegacyMisclassifiedContributionRepairService::class)->repairMember($member);

    expect($repair['contributions_removed'])->toBe(2)
        ->and($repair['repayments_posted'])->toBe(2)
        ->and(Contribution::query()->where('member_id', $member->id)->where('status', 'posted')->count())->toBe(0)
        ->and(LoanRepayment::query()->where('loan_id', $loan->id)->count())->toBe(2)
        ->and((float) $member->fresh()->getCashBalance())->toBe(0.0);
});

test('misclassified contribution repair converts jul disbursement window payment to loan repayment', function () {
    $member = Member::create([
        'member_number' => '58-REPAIR',
        'name' => 'Jul Window Repair Member',
        'email' => 'jul-window-repair@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => '2019-01-04',
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 1000)->first()
        ?? LoanTier::create([
            'tier_number' => 103,
            'label' => 'Jul repair tier',
            'min_amount' => 10_000,
            'max_amount' => 30_000,
            'min_monthly_installment' => 1000,
            'is_active' => true,
        ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 20_000,
        'amount_requested' => 20_000,
        'amount_approved' => 20_000,
        'amount_disbursed' => 20_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2020-07-10',
        'approved_at' => '2020-07-10',
        'applied_at' => '2020-07-10',
        'installments_count' => 10,
        'grace_cycles' => 0,
        'has_grace_cycle' => false,
        'first_repayment_month' => 8,
        'first_repayment_year' => 2020,
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 500_000]);
    Account::masterFund()->update(['balance' => 500_000]);

    $importService = app(LegacyPaymentImportService::class);
    $postedAt = Carbon::parse('2020-07-29');

    $importService->postLegacyContributionForRepair(
        $member,
        8,
        2020,
        1000,
        $postedAt,
        '[legacy-routed] '.__('Routed from :period — :notes', [
            'period' => 'Jul 2020',
            'notes' => __('Misclassified legacy payment'),
        ]),
    );

    expect(Contribution::query()->where('member_id', $member->id)->where('status', 'posted')->count())->toBe(1)
        ->and(LoanRepayment::query()->where('loan_id', $loan->id)->count())->toBe(0);

    $repair = app(LegacyMisclassifiedContributionRepairService::class)->repairMember($member);

    expect($repair['contributions_removed'])->toBe(1)
        ->and($repair['repayments_posted'])->toBe(1)
        ->and(Contribution::query()->where('member_id', $member->id)->where('status', 'posted')->count())->toBe(0)
        ->and(LoanRepayment::query()->where('loan_id', $loan->id)->whereDate('paid_at', '2020-07-29')->count())->toBe(1)
        ->and((float) $member->fresh()->getCashBalance())->toBe(0.0);
});

test('legacy loan csv loan_id is preserved on import and used when classifying repayments', function () {
    $this->actingAs($this->admin, 'tenant');

    Account::masterCash()?->update(['balance' => 500_000]);
    Account::masterFund()?->update(['balance' => 500_000]);

    $legacyLoanId = 44_556;

    $membersPath = storage_path('app/legacy-loan-id-members.csv');
    $loansPath = storage_path('app/legacy-loan-id-loans.csv');
    $paymentsPath = storage_path('app/legacy-loan-id-payments.csv');

    AssociativeCsv::write($membersPath, [
        'member_number',
        'name',
        'email',
        'monthly_contribution_amount',
        'cutoff_cash_balance',
        'cutoff_fund_balance',
    ], [
        ['LEG-LID-1', 'Legacy Loan Id Member', 'legacy-loan-id@fund.test', '1000', '0', '8000'],
    ]);
    AssociativeCsv::write($loansPath, ['Loan Id', 'loan_status', 'member_number', 'amount_approved', 'disbursed_at', 'installments_count'], [
        [(string) $legacyLoanId, 'active', 'LEG-LID-1', '12000', '2025-01-15', '12'],
    ]);
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], [
        ['LEG-LID-1', '2025-02-01', '1000'],
    ]);

    $classification = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
        $membersPath,
        $loansPath,
    );

    expect($classification['rows'][0]['payment_type'])->toBe('loan_repayment')
        ->and($classification['rows'][0]['loan_number'])->toBe((string) $legacyLoanId);

    $import = app(LegacyMigrationOrchestrator::class)->importMembersAndLoans([
        'default_password' => 'password123',
        'members_path' => $membersPath,
        'loans_path' => $loansPath,
        'grace_cycles' => 0,
    ], '2025-12-31');

    expect($import['loans']['failed'] ?? 1)->toBe(0);

    $loan = Loan::query()->find($legacyLoanId);

    expect($loan)->not->toBeNull()
        ->and($loan->member->member_number)->toBe('LEG-LID-1')
        ->and($loan->disbursed_at?->toDateString())->toBe('2025-01-15');

    @unlink($membersPath);
    @unlink($loansPath);
    @unlink($paymentsPath);
});

test('payment classifier prefers loans csv legacy loan_id over stale database loan', function () {
    $this->actingAs($this->admin, 'tenant');

    $legacyLoanId = 94;

    $member = Member::create([
        'member_number' => '58',
        'name' => 'Stale Db Loan Member',
        'email' => 'stale-db-loan@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => '2019-01-04',
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 1000)->first()
        ?? LoanTier::create([
            'tier_number' => 98,
            'label' => 'Stale DB loan tier',
            'min_amount' => 10_000,
            'max_amount' => 30_000,
            'min_monthly_installment' => 1000,
            'is_active' => true,
        ]);

    Loan::create([
        'id' => 81,
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 20_000,
        'amount_requested' => 20_000,
        'amount_approved' => 20_000,
        'amount_disbursed' => 20_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2020-07-10',
        'approved_at' => '2020-07-10',
        'applied_at' => '2020-07-10',
        'installments_count' => 12,
        'grace_cycles' => 0,
        'has_grace_cycle' => false,
        'first_repayment_month' => 8,
        'first_repayment_year' => 2020,
    ]);

    $membersPath = storage_path('app/stale-db-loan-members.csv');
    $loansPath = storage_path('app/stale-db-loan-loans.csv');
    $paymentsPath = storage_path('app/stale-db-loan-payments.csv');

    AssociativeCsv::write($membersPath, [
        'member_number',
        'name',
        'email',
        'monthly_contribution_amount',
        'cutoff_cash_balance',
        'cutoff_fund_balance',
    ], [
        ['58', 'Stale Db Loan Member', 'stale-db-loan@fund.test', '500', '0', '0'],
    ]);
    AssociativeCsv::write($loansPath, ['Loan Id', 'loan_status', 'member_number', 'amount_approved', 'disbursed_at', 'installments_count'], [
        [(string) $legacyLoanId, 'active', '58', '20000', '2020-07-10', '12'],
    ]);
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount', 'payment_type'], [
        ['58', '2020-07-29', '1000', 'contribution'],
    ]);

    $classification = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
        $membersPath,
        $loansPath,
        0,
    );

    expect($classification['rows'][0]['payment_type'])->toBe('loan_repayment')
        ->and($classification['rows'][0]['loan_number'])->toBe((string) $legacyLoanId)
        ->and($classification['stats']['loan_repayment'])->toBe(1);

    Account::masterCash()?->update(['balance' => 500_000]);
    Account::masterFund()?->update(['balance' => 500_000]);

    $preview = app(LegacyMigrationOrchestrator::class)->previewPaymentClassification([
        'default_password' => 'password12345',
        'members_path' => $membersPath,
        'loans_path' => $loansPath,
        'payments_path' => $paymentsPath,
        'strategy' => 'historical',
        'grace_cycles' => 0,
    ]);

    expect($preview['rows'][0]['payment_type'])->toBe('loan_repayment')
        ->and($preview['rows'][0]['loan_number'])->toBe((string) $legacyLoanId)
        ->and($preview['stats']['loan_repayments'])->toBe(1);

    @unlink($membersPath);
    @unlink($loansPath);
    @unlink($paymentsPath);
});

test('payment classifier uses loans csv disbursement window without mapping stale database loan id', function () {
    $member = Member::create([
        'member_number' => '58',
        'name' => 'Csv Window Only Member',
        'email' => 'csv-window-only@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => '2019-01-04',
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 1000)->first()
        ?? LoanTier::create([
            'tier_number' => 99,
            'label' => 'Csv window tier',
            'min_amount' => 10_000,
            'max_amount' => 30_000,
            'min_monthly_installment' => 1000,
            'is_active' => true,
        ]);

    Loan::create([
        'id' => 81,
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 20_000,
        'amount_requested' => 20_000,
        'amount_approved' => 20_000,
        'amount_disbursed' => 20_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2020-07-10',
        'approved_at' => '2020-07-10',
        'applied_at' => '2020-07-10',
        'installments_count' => 12,
        'grace_cycles' => 0,
        'has_grace_cycle' => false,
    ]);

    $membersPath = storage_path('app/csv-window-members.csv');
    $loansPath = storage_path('app/csv-window-loans.csv');
    $paymentsPath = storage_path('app/csv-window-payments.csv');

    AssociativeCsv::write($membersPath, ['member_number', 'name', 'email', 'monthly_contribution_amount'], [
        ['58', 'Csv Window Only Member', 'csv-window-only@fund.test', '500'],
    ]);
    AssociativeCsv::write($loansPath, ['Loan Id', 'loan_status', 'member_number', 'amount_approved', 'disbursed_at', 'installments_count'], [
        ['94', 'active', '58', '20000', '2020-07-10', '12'],
    ]);
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], [
        ['58', '2020-07-29', '1000'],
    ]);

    $classification = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
        $membersPath,
        $loansPath,
        0,
    );

    expect($classification['rows'][0]['payment_type'])->toBe('loan_repayment')
        ->and($classification['rows'][0]['loan_number'])->toBe('94');

    @unlink($membersPath);
    @unlink($loansPath);
    @unlink($paymentsPath);
});

test('payment classifier does not open a new loan repayment window until the prior loan is repaid', function () {
    $member = Member::create([
        'member_number' => 'LEG-SEQ-WINDOW',
        'name' => 'Sequential Window Member',
        'email' => 'seq-window@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('tier_number', 0)->first()
        ?? LoanTier::create([
            'tier_number' => 0,
            'label' => 'Tier 0',
            'min_amount' => 1_000,
            'max_amount' => 5_000,
            'min_monthly_installment' => 500,
            'is_active' => true,
        ]);

    $olderLoan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 5_000,
        'amount_requested' => 5_000,
        'amount_approved' => 5_000,
        'amount_disbursed' => 5_000,
        'interest_rate' => 0,
        'term_months' => 6,
        'monthly_repayment' => 500,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2023-10-29',
        'approved_at' => '2023-10-29',
        'applied_at' => '2023-10-29',
        'installments_count' => 6,
    ]);

    $newerLoan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 5_000,
        'amount_requested' => 5_000,
        'amount_approved' => 5_000,
        'amount_disbursed' => 5_000,
        'interest_rate' => 0,
        'term_months' => 6,
        'monthly_repayment' => 500,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2025-06-02',
        'approved_at' => '2025-06-02',
        'applied_at' => '2025-06-02',
        'installments_count' => 6,
    ]);

    $paymentsPath = storage_path('app/classify-seq-window-payments.csv');
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount', 'payment_type'], [
        ['LEG-SEQ-WINDOW', '2023-12-01', '500', ''],
        ['LEG-SEQ-WINDOW', '2024-01-01', '500', ''],
        ['LEG-SEQ-WINDOW', '2025-07-01', '500', ''],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
    );

    $rowsByDate = collect($result['rows'])->keyBy('payment_date');

    expect($rowsByDate['2023-12-01']['payment_type'])->toBe('loan_repayment')
        ->and($rowsByDate['2023-12-01']['loan_number'])->toBe((string) $olderLoan->id)
        ->and($rowsByDate['2024-01-01']['loan_number'])->toBe((string) $olderLoan->id)
        ->and($rowsByDate['2025-07-01']['loan_number'])->toBe((string) $olderLoan->id);

    $closedOlderPath = storage_path('app/classify-seq-window-closed-payments.csv');
    AssociativeCsv::write($closedOlderPath, ['member_number', 'payment_date', 'amount', 'payment_type'], [
        ['LEG-SEQ-WINDOW', '2023-12-01', '500', ''],
        ['LEG-SEQ-WINDOW', '2024-01-01', '500', ''],
        ['LEG-SEQ-WINDOW', '2024-02-01', '500', ''],
        ['LEG-SEQ-WINDOW', '2024-03-01', '500', ''],
        ['LEG-SEQ-WINDOW', '2024-04-01', '500', ''],
        ['LEG-SEQ-WINDOW', '2024-05-01', '500', ''],
        ['LEG-SEQ-WINDOW', '2024-06-01', '500', ''],
        ['LEG-SEQ-WINDOW', '2025-07-01', '500', ''],
    ]);

    $closedResult = app(LegacyPaymentClassifierService::class)->classifyFile(
        $closedOlderPath,
        now()->parse('2025-12-31'),
    );

    $closedRowsByDate = collect($closedResult['rows'])->keyBy('payment_date');

    expect($closedRowsByDate['2025-07-01']['payment_type'])->toBe('loan_repayment')
        ->and($closedRowsByDate['2025-07-01']['loan_number'])->toBe((string) $newerLoan->id);

    @unlink($paymentsPath);
    @unlink($closedOlderPath);
});

test('legacy payment import posts loan repayments to the explicit loan_number in the classified blueprint', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'LEG-SEQ-IMPORT',
        'name' => 'Sequential Import Member',
        'email' => 'seq-import@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('tier_number', 0)->first()
        ?? LoanTier::create([
            'tier_number' => 0,
            'label' => 'Tier 0',
            'min_amount' => 1_000,
            'max_amount' => 5_000,
            'min_monthly_installment' => 500,
            'is_active' => true,
        ]);

    $olderLoan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 5_000,
        'amount_requested' => 5_000,
        'amount_approved' => 5_000,
        'amount_disbursed' => 5_000,
        'interest_rate' => 0,
        'term_months' => 6,
        'monthly_repayment' => 500,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2023-10-29',
        'approved_at' => '2023-10-29',
        'applied_at' => '2023-10-29',
        'installments_count' => 6,
    ]);

    $newerLoan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 5_000,
        'amount_requested' => 5_000,
        'amount_approved' => 5_000,
        'amount_disbursed' => 5_000,
        'interest_rate' => 0,
        'term_months' => 6,
        'monthly_repayment' => 500,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2025-06-02',
        'approved_at' => '2025-06-02',
        'applied_at' => '2025-06-02',
        'installments_count' => 6,
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 500_000]);
    Account::masterFund()->update(['balance' => 500_000]);

    $classifiedPath = storage_path('app/seq-import-payments.csv');
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'loan_number',
        'period',
        'notes',
    ], [
        [
            '',
            'LEG-SEQ-IMPORT',
            '2025-07-01',
            '500',
            'loan_repayment',
            (string) $newerLoan->id,
            '',
            'Misclassified to newer loan',
        ],
    ]);

    $import = app(LegacyPaymentImportService::class)->import($classifiedPath);

    expect($import['loan_repayments'])->toBe(1)
        ->and(LoanRepayment::query()->where('loan_id', $olderLoan->id)->count())->toBe(0)
        ->and(LoanRepayment::query()->where('loan_id', $newerLoan->id)->count())->toBe(1);

    @unlink($classifiedPath);
});

test('payment classifier allocates overpayment across multiple installments before contributing', function () {
    $member = Member::create([
        'member_number' => '23',
        'name' => 'Overpayment Member',
        'email' => 'overpayment-member@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 2500)->first()
        ?? LoanTier::create([
            'tier_number' => 98,
            'label' => 'Overpayment tier',
            'min_amount' => 50_000,
            'max_amount' => 100_000,
            'min_monthly_installment' => 2500,
            'is_active' => true,
        ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 80_000,
        'amount_requested' => 80_000,
        'amount_approved' => 80_000,
        'amount_disbursed' => 80_000,
        'interest_rate' => 0,
        'term_months' => 24,
        'monthly_repayment' => 2500,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2021-08-28',
        'approved_at' => '2021-08-28',
        'applied_at' => '2021-08-28',
        'installments_count' => 24,
        'grace_cycles' => 0,
        'has_grace_cycle' => false,
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 2500,
        'due_date' => '2021-09-28',
        'status' => 'pending',
    ]);

    $paymentsPath = storage_path('app/classify-overpayment-payments.csv');
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount', 'payment_type'], [
        ['23', '2021-09-28', '5000', 'loan_repayment'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
    );

    expect($result['rows'][0]['payment_type'])->toBe('loan_repayment')
        ->and($result['rows'][0]['loan_number'])->toBe((string) $loan->id)
        ->and($result['rows'][0]['amount'])->toBe('5000')
        ->and($result['stats']['loan_repayment'])->toBe(1)
        ->and($result['stats']['contribution'])->toBe(0);

    @unlink($paymentsPath);
});

test('legacy payment import applies overpayment across multiple installments on schedule sync', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'LEG-OVERPAY',
        'name' => 'Overpayment Import Member',
        'email' => 'overpayment-import@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 2500)->first()
        ?? LoanTier::create([
            'tier_number' => 99,
            'label' => 'Overpayment import tier',
            'min_amount' => 50_000,
            'max_amount' => 100_000,
            'min_monthly_installment' => 2500,
            'is_active' => true,
        ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 80_000,
        'amount_requested' => 80_000,
        'amount_approved' => 80_000,
        'amount_disbursed' => 80_000,
        'interest_rate' => 0,
        'term_months' => 24,
        'monthly_repayment' => 2500,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2021-08-28',
        'approved_at' => '2021-08-28',
        'applied_at' => '2021-08-28',
        'installments_count' => 24,
    ]);

    foreach (range(1, 4) as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 2500,
            'due_date' => Carbon::parse('2021-08-28')->addMonths($number)->toDateString(),
            'status' => 'pending',
        ]);
    }

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 500_000]);
    Account::masterFund()->update(['balance' => 500_000]);

    $classifiedPath = storage_path('app/legacy-overpayment-import-test.csv');
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'loan_number',
        'period',
        'notes',
    ], [
        ['', 'LEG-OVERPAY', '2021-09-28', '5000', 'loan_repayment', (string) $loan->id, '', 'Double installment payment'],
    ]);

    $result = app(LegacyPaymentImportService::class)->import($classifiedPath);

    expect($result['loan_repayments'])->toBe(1)
        ->and($result['contributions'])->toBe(0)
        ->and($result['failed'])->toBe(0)
        ->and((float) LoanRepayment::query()->where('loan_id', $loan->id)->value('amount'))->toBe(5000.0)
        ->and($loan->fresh()->installments()->where('status', 'paid')->count())->toBe(2);

    @unlink($classifiedPath);
});

test('legacy payment import reclassifies below minimum installment loan repayments only at cycle start', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'LEG-BELOW-EMI',
        'name' => 'Below EMI Import Member',
        'email' => 'below-emi-import@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 2500)->first()
        ?? LoanTier::create([
            'tier_number' => 96,
            'label' => 'Below EMI import tier',
            'min_amount' => 50_000,
            'max_amount' => 100_000,
            'min_monthly_installment' => 2500,
            'is_active' => true,
        ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 80_000,
        'amount_requested' => 80_000,
        'amount_approved' => 80_000,
        'amount_disbursed' => 80_000,
        'interest_rate' => 0,
        'term_months' => 24,
        'monthly_repayment' => 2500,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2021-08-28',
        'approved_at' => '2021-08-28',
        'applied_at' => '2021-08-28',
        'installments_count' => 24,
    ]);

    foreach (range(1, 24) as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 2500,
            'due_date' => Carbon::parse('2021-08-28')->addMonths($number)->toDateString(),
            'status' => 'pending',
        ]);
    }

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 500_000]);
    Account::masterFund()->update(['balance' => 500_000]);

    $classifiedPath = storage_path('app/legacy-below-emi-import-test.csv');
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'loan_number',
        'period',
        'notes',
    ], [
        ['', 'LEG-BELOW-EMI', '2021-08-28', '1000', 'loan_repayment', (string) $loan->id, '', 'Partial same-day payment'],
        ['', 'LEG-BELOW-EMI', '2021-09-28', '2500', 'loan_repayment', (string) $loan->id, '', 'First full installment'],
    ]);

    $result = app(LegacyPaymentImportService::class)->import($classifiedPath);

    expect($result['contributions'])->toBe(0)
        ->and($result['loan_repayments'])->toBe(2)
        ->and($result['reclassified_as_contribution'])->toBe(0)
        ->and($result['failed'])->toBe(0)
        ->and(Contribution::query()->where('member_id', $member->id)->count())->toBe(0)
        ->and(LoanRepayment::query()->where('loan_id', $loan->id)->count())->toBe(2)
        ->and((float) LoanRepayment::query()->where('loan_id', $loan->id)->sum('amount'))->toBe(3500.0);

    $rows = AssociativeCsv::read($classifiedPath);

    expect($rows[0]['payment_type'])->toBe('loan_repayment')
        ->and($rows[0]['amount'])->toBe('1000')
        ->and($rows[1]['payment_type'])->toBe('loan_repayment');

    $loan->refresh();

    expect($loan->installments()->where('status', 'paid')->count())->toBe(1)
        ->and($loan->installments()->where('status', 'pending')->count())->toBe(23);

    @unlink($classifiedPath);
});

test('legacy payment import posts multiple same-day loan repayments with identical amounts', function () {
    $member = Member::create([
        'member_number' => 'LEG-SAME-DAY',
        'name' => 'Same Day Repay Member',
        'email' => 'same-day-repay@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(5),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 60_000,
        'amount_requested' => 60_000,
        'amount_approved' => 60_000,
        'amount_disbursed' => 60_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 5500,
        'total_repaid' => 0,
        'member_portion' => 40_000,
        'master_portion' => 20_000,
        'settlement_threshold' => 0,
        'status' => 'active',
        'disbursed_at' => '2023-06-13',
        'approved_at' => '2023-06-13',
        'applied_at' => '2023-06-13',
        'installments_count' => 12,
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 500_000]);
    Account::masterFund()->update(['balance' => 500_000]);

    $classifiedPath = storage_path('app/legacy-same-day-repay-test.csv');
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'loan_number',
        'period',
        'notes',
    ], [
        ['', 'LEG-SAME-DAY', '2023-10-29', '5500', 'loan_repayment', (string) $loan->id, '', 'Classifier row 1001'],
        ['', 'LEG-SAME-DAY', '2023-10-29', '5500', 'loan_repayment', (string) $loan->id, '', 'Classifier row 1002'],
        ['', 'LEG-SAME-DAY', '2023-10-29', '5500', 'loan_repayment', (string) $loan->id, '', 'Classifier row 1003'],
    ]);

    $result = app(LegacyPaymentImportService::class)->import($classifiedPath);

    expect($result['loan_repayments'])->toBe(3)
        ->and($result['failed'])->toBe(0)
        ->and(LoanRepayment::query()->where('loan_id', $loan->id)->count())->toBe(3)
        ->and((float) LoanRepayment::query()->where('loan_id', $loan->id)->whereDate('paid_at', '2023-10-29')->sum('amount'))->toBe(16500.0);

    $secondImport = app(LegacyPaymentImportService::class)->import($classifiedPath);

    expect($secondImport['loan_repayments'])->toBe(0)
        ->and(LoanRepayment::query()->where('loan_id', $loan->id)->count())->toBe(3);

    @unlink($classifiedPath);
});

test('payment classifier treats post disbursement payments as loan repayments until target is met', function () {
    LegacyMigrationDateFormatSettings::saveSlashDateFormat('d/m/Y');

    $membersPath = storage_path('app/classify-window-members.csv');
    $loansPath = storage_path('app/classify-window-loans.csv');
    $paymentsPath = storage_path('app/classify-window-payments.csv');

    AssociativeCsv::write($membersPath, ['member_number', 'name', 'email', 'monthly_contribution_amount'], [
        ['1', 'Window Member', '', '1000'],
    ]);
    AssociativeCsv::write($loansPath, ['member_number', 'amount_approved', 'disbursed_at', 'loan_status'], [
        ['1', '12000', '2/25/2016', 'active'],
    ]);
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], [
        ['1', '8/10/2014', '1000'],
        ['1', '20/12/2014', '1000'],
        ['1', '5/11/2016', '1000'],
        ['1', '5/12/2016', '1000'],
        ['1', '5/1/2017', '1000'],
        ['1', '5/2/2018', '1000'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
        $membersPath,
        $loansPath,
    );

    expect($result['rows'][0]['payment_type'])->toBe('contribution')
        ->and($result['rows'][1]['payment_type'])->toBe('contribution')
        ->and($result['rows'][2]['payment_type'])->toBe('loan_repayment')
        ->and($result['rows'][3]['payment_type'])->toBe('loan_repayment')
        ->and($result['rows'][4]['payment_type'])->toBe('loan_repayment')
        ->and($result['rows'][5]['payment_type'])->toBe('loan_repayment')
        ->and($result['stats']['loan_repayment'])->toBe(4)
        ->and($result['stats']['contribution'])->toBe(2);

    @unlink($membersPath);
    @unlink($loansPath);
    @unlink($paymentsPath);
});

test('payment classifier does not suggest loan repayment before loan disbursement date', function () {
    LegacyMigrationDateFormatSettings::saveSlashDateFormat('d/m/Y');

    $membersPath = storage_path('app/classify-preloan-members.csv');
    $loansPath = storage_path('app/classify-preloan-loans.csv');
    $paymentsPath = storage_path('app/classify-preloan-payments.csv');

    AssociativeCsv::write($membersPath, ['member_number', 'name', 'email', 'monthly_contribution_amount'], [
        ['1', 'Pre Loan Member', '', '1000'],
    ]);
    AssociativeCsv::write($loansPath, ['member_number', 'amount_approved', 'disbursed_at', 'loan_status'], [
        ['1', '150000', '2/25/2016', 'active'],
    ]);
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], [
        ['1', '8/10/2014', '1000'],
        ['1', '20/12/2014', '1000'],
        ['1', '20/01/2015', '5500'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
        $membersPath,
        $loansPath,
    );

    expect($result['stats']['loan_repayment'])->toBe(0)
        ->and($result['stats']['contribution'])->toBe(3)
        ->and($result['stats']['unclassified'])->toBe(0)
        ->and($result['rows'][0]['payment_type'])->toBe('contribution')
        ->and($result['rows'][1]['payment_type'])->toBe('contribution')
        ->and($result['rows'][2]['payment_type'])->toBe('contribution');

    @unlink($membersPath);
    @unlink($loansPath);
    @unlink($paymentsPath);
});

test('legacy migration date parser preserves iso payment dates and us slash dates', function () {
    LegacyMigrationDateFormatSettings::saveSlashDateFormat('m/d/Y');

    expect(LegacyMigrationDateParser::parse('2025-10-01', 2)->toDateString())->toBe('2025-10-01')
        ->and(LegacyMigrationDateParser::parse('8/10/2014', 2)->toDateString())->toBe('2014-08-10')
        ->and(LegacyMigrationDateParser::parse('2/25/2016', 2)->toDateString())->toBe('2016-02-25')
        ->and(LegacyMigrationDateParser::parse('11/3/2025', 2)->toDateString())->toBe('2025-11-03')
        ->and(LegacyMigrationDateParser::parse('6/2/2025', 2)->toDateString())->toBe('2025-06-02');
});

test('payment classifier suggests loan repayment when active loan outstanding covers amount', function () {
    $member = Member::create([
        'member_number' => 'LEG-002',
        'name' => 'Borrower Member',
        'email' => 'borrower-member@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    Loan::create([
        'member_id' => $member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'interest_rate' => 0,
        'term_months' => 10,
        'monthly_repayment' => 500,
        'total_repaid' => 2000,
        'installments_count' => 10,
        'status' => 'active',
        'disbursed_at' => '2025-01-15',
        'purpose' => 'Test loan',
    ]);

    $path = storage_path('app/legacy-migration-test-payments-loan.csv');

    AssociativeCsv::write($path, ['member_email', 'payment_date', 'amount'], [
        ['member_email' => 'borrower-member@fund.test', 'payment_date' => '2025-10-01', 'amount' => '500'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile($path, now()->parse('2025-12-31'));

    expect($result['stats']['loan_repayment'])->toBe(1)
        ->and($result['rows'][0]['payment_type'])->toBe('loan_repayment');

    @unlink($path);
});

test('legacy migration apply replays classified csv on step 5', function () {
    Filament::setCurrentPanel('tenant');

    Storage::disk('local')->put('legacy-migration/working/members.csv', implode("\n", [
        'member_number,name,email,monthly_contribution_amount,cutoff_cash_balance,cutoff_fund_balance',
        'RUN-1,Run Member,run-member@fund.test,1000,0,0',
    ]));

    $this->actingAs($this->admin, 'tenant');

    app(LegacyMigrationOrchestrator::class)->importMembers([
        'default_password' => 'password123',
        'members_path' => Storage::disk('local')->path('legacy-migration/working/members.csv'),
    ], '2025-12-31');

    Storage::disk('local')->put('legacy-migration/last-classified-payments.csv', implode("\n", [
        'member_email,member_number,payment_date,amount,payment_type,loan_number,period,notes',
        ',RUN-1,2025-10-01,1000,contribution,,2025-10,',
    ]));

    Setting::set('legacy_migration', 'members_imported', '1');
    Setting::set('legacy_migration', 'loans_imported', '1');
    Setting::set('legacy_migration', 'classify_status', 'completed');
    Setting::set('legacy_migration', 'classify_stats', json_encode(['contributions' => 1, 'loan_repayments' => 0, 'failed' => 0]));

    Livewire::actingAs($this->admin, 'tenant')
        ->test(LegacyMigrationPage::class)
        ->set('currentStep', 5)
        ->call('runMigration', false)
        ->assertNotified(__('Migration complete'));

    expect(Setting::get('legacy_migration', 'run_status'))->toBe('completed')
        ->and(Member::query()->where('email', 'run-member@fund.test')->exists())->toBeTrue();

    Storage::disk('local')->delete([
        'legacy-migration/working/members.csv',
        'legacy-migration/last-classified-payments.csv',
    ]);
});

test('legacy migration payments job authenticates queued admin without ambient session', function () {
    Notification::fake();

    auth('tenant')->logout();

    $member = Member::create([
        'member_number' => 'JOB-PAY-1',
        'name' => 'Job Payment Member',
        'email' => 'job-payment@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 100_000]);
    Account::masterFund()->update(['balance' => 100_000]);
    AccountingService::withoutMemberCashCollection(
        fn () => app(AccountingService::class)->credit($member->cashAccount, 5000, 'Seed'),
    );

    $classifiedPath = storage_path('app/legacy-migration-job-payments.csv');
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'suggested_loan_number',
        'period',
        'notes',
    ], [
        ['', 'JOB-PAY-1', '2025-10-01', '1000', 'contribution', '', '2025-10', ''],
    ]);

    Setting::set('legacy_migration', 'run_status', 'running');
    Setting::set('legacy_migration', 'last_run', json_encode([
        'members' => ['created' => 1, 'skipped' => 0, 'failed' => 0, 'errors' => []],
    ]));

    RunLegacyMigrationPaymentsJob::dispatch(
        [
            'strategy' => 'historical',
            'classified_payments_path' => $classifiedPath,
        ],
        [],
        $this->admin->id,
    );

    expect(Setting::get('legacy_migration', 'run_status'))->toBe('completed')
        ->and(json_decode((string) Setting::get('legacy_migration', 'last_run'), true)['payments']['contributions'] ?? 0)->toBe(1);

    @unlink($classifiedPath);
});

test('legacy payment import marks installments paid from bulk repayments', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'LEG-SCHEDULE',
        'name' => 'Schedule Sync Member',
        'email' => 'schedule-sync@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(5),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 100_000]);
    Account::masterFund()->update(['balance' => 100_000]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 4546,
        'amount_requested' => 4546,
        'amount_approved' => 4546,
        'amount_disbursed' => 4546,
        'interest_rate' => 0,
        'term_months' => 3,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2024-01-01',
        'approved_at' => '2024-01-01',
        'applied_at' => '2024-01-01',
        'installments_count' => 3,
    ]);

    foreach ([1, 2, 3] as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 1000,
            'due_date' => sprintf('2024-0%d-05', $number),
            'status' => 'pending',
        ]);
    }

    $classifiedPath = storage_path('app/legacy-schedule-sync-test.csv');
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'suggested_loan_number',
        'period',
        'notes',
    ], [
        ['', 'LEG-SCHEDULE', '2024-01-05', '1000', 'loan_repayment', (string) $loan->id, '', ''],
        ['', 'LEG-SCHEDULE', '2024-02-05', '1000', 'loan_repayment', (string) $loan->id, '', ''],
        ['', 'LEG-SCHEDULE', '2024-03-05', '1000', 'loan_repayment', (string) $loan->id, '', ''],
    ]);

    $result = app(LegacyPaymentImportService::class)->import($classifiedPath);

    $member = $member->fresh();
    $masterCash = Account::masterCash()?->fresh();
    $loanAccount = Account::query()
        ->where('type', 'loan')
        ->where('loan_id', $loan->id)
        ->first();

    expect($result['loan_repayments'])->toBe(3)
        ->and($result['failed'])->toBe(0)
        ->and($loan->installments()->where('status', 'paid')->count())->toBe(3)
        ->and($loan->fresh()->status)->toBe('completed')
        ->and((float) $member->cashAccount->balance)->toBe(0.0)
        ->and((float) ($masterCash?->balance ?? 0))->toBe(0.0)
        ->and((float) $member->fundAccount->balance)->toBe(3000.0)
        ->and((float) ($loanAccount?->balance ?? 0))->toBe(3000.0);

    $repaymentDates = LoanRepayment::query()
        ->where('loan_id', $loan->id)
        ->get()
        ->flatMap(fn ($repayment) => $repayment->transactions->pluck('transacted_at'))
        ->filter()
        ->map(fn ($date) => Carbon::parse((string) $date)->toDateString())
        ->unique()
        ->values()
        ->all();
    expect($repaymentDates)->toBe(['2024-01-05', '2024-02-05', '2024-03-05']);

    @unlink($classifiedPath);
});

test('legacy sync loan schedules command settles existing imported repayments', function () {
    $member = Member::create([
        'member_number' => 'LEG-SYNC-CMD',
        'name' => 'Sync Command Member',
        'email' => 'sync-cmd@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(5),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
        'interest_rate' => 0,
        'term_months' => 2,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2024-01-01',
        'approved_at' => '2024-01-01',
        'applied_at' => '2024-01-01',
        'installments_count' => 2,
    ]);

    foreach ([1, 2] as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 1000,
            'due_date' => sprintf('2024-0%d-05', $number + 3),
            'status' => 'pending',
        ]);
    }

    $loan->repayments()->create([
        'amount' => 1000,
        'paid_at' => '2024-04-05',
        'notes' => 'Pre-imported repayment',
    ]);
    $loan->repayments()->create([
        'amount' => 1000,
        'paid_at' => '2024-05-05',
        'notes' => 'Pre-imported repayment',
    ]);

    $result = app(LegacyImportedLoanScheduleSyncService::class)->syncLoan($loan);

    expect($result)->toBe(2)
        ->and($loan->installments()->where('status', 'paid')->count())->toBe(2)
        ->and($loan->fresh()->status)->toBe('completed');
});

test('legacy payment import assigns repayments to the correct loan when member has multiple loans', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'LEG-MULTI-IMPORT',
        'name' => 'Multi Import Member',
        'email' => 'multi-import@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 200_000]);
    Account::masterFund()->update(['balance' => 200_000]);

    $olderLoan = Loan::create([
        'member_id' => $member->id,
        'amount' => 2000,
        'amount_requested' => 2000,
        'amount_approved' => 2000,
        'amount_disbursed' => 2000,
        'interest_rate' => 0,
        'term_months' => 2,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'master_portion' => 1000,
        'status' => 'active',
        'disbursed_at' => '2016-01-01',
        'approved_at' => '2016-01-01',
        'applied_at' => '2016-01-01',
        'installments_count' => 2,
    ]);

    $newerLoan = Loan::create([
        'member_id' => $member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
        'interest_rate' => 0,
        'term_months' => 5,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'master_portion' => 2500,
        'status' => 'active',
        'disbursed_at' => '2018-01-01',
        'approved_at' => '2018-01-01',
        'applied_at' => '2018-01-01',
        'installments_count' => 5,
    ]);

    $olderTarget = LegacyLoanRepaymentTarget::forLoan($olderLoan);
    $classifiedPath = storage_path('app/legacy-multi-loan-import-test.csv');
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'loan_number',
        'period',
        'notes',
    ], [
        ['', 'LEG-MULTI-IMPORT', '2016-06-01', (string) $olderTarget, 'loan_repayment', (string) $olderLoan->id, '', ''],
        ['', 'LEG-MULTI-IMPORT', '2018-06-01', (string) $olderTarget, 'loan_repayment', (string) $olderLoan->id, '', ''],
        ['', 'LEG-MULTI-IMPORT', '2018-07-01', '1000', 'loan_repayment', (string) $newerLoan->id, '', ''],
    ]);

    $result = app(LegacyPaymentImportService::class)->import($classifiedPath);

    expect($result['loan_repayments'])->toBe(3)
        ->and($result['failed'])->toBe(0);

    $olderDescriptions = Transaction::query()
        ->where('member_id', $member->id)
        ->where('description', 'like', '%Loan #'.$olderLoan->id.' repayments (import, bulk)%')
        ->count();

    $newerDescriptions = Transaction::query()
        ->where('member_id', $member->id)
        ->where('description', 'like', '%Loan #'.$newerLoan->id.' repayments (import, bulk)%')
        ->count();

    expect($olderDescriptions)->toBeGreaterThan(0)
        ->and($newerDescriptions)->toBeGreaterThan(0)
        ->and(LoanRepayment::query()->where('loan_id', $olderLoan->id)->count())->toBe(2)
        ->and(LoanRepayment::query()->where('loan_id', $newerLoan->id)->count())->toBe(1);

    @unlink($classifiedPath);
});

test('legacy payment import reclassifies loan repayment rows as contributions when no loan is found', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'LEG-NO-LOAN',
        'name' => 'No Loan Member',
        'email' => 'no-loan-member@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(5),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 100_000]);
    Account::masterFund()->update(['balance' => 100_000]);

    $classifiedPath = storage_path('app/legacy-no-loan-repayment-test.csv');
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'loan_number',
        'period',
        'notes',
    ], [
        ['', 'LEG-NO-LOAN', '2024-03-15', '1500', 'loan_repayment', '', '', 'Misclassified repayment'],
    ]);

    $result = app(LegacyPaymentImportService::class)->import($classifiedPath);

    expect($result['contributions'])->toBe(1)
        ->and($result['loan_repayments'])->toBe(0)
        ->and($result['failed'])->toBe(0)
        ->and($result['reclassified_as_contribution'])->toBe(1)
        ->and(Contribution::query()->where('member_id', $member->id)->count())->toBe(1)
        ->and(LoanRepayment::query()->count())->toBe(0);

    $rows = AssociativeCsv::read($classifiedPath);

    expect($rows[0]['payment_type'])->toBe('contribution')
        ->and($rows[0]['loan_number'])->toBe('')
        ->and($rows[0]['period'])->toBe('2024-03');

    @unlink($classifiedPath);
});

test('legacy migration payment classification preview matches import reclassification outcomes', function () {
    $this->actingAs($this->admin, 'tenant');

    $membersPath = storage_path('app/preview-match-members.csv');
    $loansPath = storage_path('app/preview-match-loans.csv');
    $paymentsPath = storage_path('app/preview-match-payments.csv');

    AssociativeCsv::write($membersPath, [
        'member_number',
        'name',
        'email',
        'monthly_contribution_amount',
        'cutoff_cash_balance',
        'cutoff_fund_balance',
    ], [
        ['PREV-1', 'Preview Member', 'preview-match@fund.test', '1000', '0', '0'],
    ]);
    AssociativeCsv::write($loansPath, ['member_number', 'amount_approved', 'disbursed_at', 'loan_status'], []);
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount', 'payment_type'], [
        ['PREV-1', '2024-03-15', '1500', 'loan_repayment'],
    ]);

    Account::masterCash()->update(['balance' => 100_000]);
    Account::masterFund()->update(['balance' => 100_000]);

    $preview = app(LegacyMigrationOrchestrator::class)->previewPaymentClassification([
        'default_password' => 'password12345',
        'members_path' => $membersPath,
        'loans_path' => $loansPath,
        'payments_path' => $paymentsPath,
        'strategy' => 'historical',
    ]);

    expect($preview['stats']['contributions'])->toBe(1)
        ->and($preview['stats']['loan_repayments'])->toBe(0)
        ->and($preview['rows'][0]['migration_outcome'])->toBe('contribution')
        ->and($preview['rows'][0]['payment_type'])->toBe('contribution')
        ->and(Member::query()->where('member_number', 'PREV-1')->exists())->toBeFalse();

    @unlink($membersPath);
    @unlink($loansPath);
    @unlink($paymentsPath);
});

test('legacy payment import rejects unsupported future payment dates with clear errors', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'LEG-FUTURE-DATE',
        'name' => 'Future Date Member',
        'email' => 'future-date-member@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(5),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 100_000]);
    Account::masterFund()->update(['balance' => 100_000]);

    $classifiedPath = storage_path('app/legacy-future-date-test.csv');
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'loan_number',
        'period',
        'notes',
    ], [
        ['', 'LEG-FUTURE-DATE', '2051-06-12', '500', 'contribution', '', '2051-06', 'Out of range date'],
    ]);

    $result = app(LegacyPaymentImportService::class)->import($classifiedPath);

    expect($result['failed'])->toBe(1)
        ->and($result['contributions'])->toBe(0)
        ->and($result['loan_repayments'])->toBe(0)
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0])->toContain('beyond supported import range')
        ->and(Contribution::query()->where('member_id', $member->id)->count())->toBe(0)
        ->and(LoanRepayment::query()->count())->toBe(0);

    @unlink($classifiedPath);
});

test('legacy payment import does not rewrite classified csv when re-importing existing loan repayments', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'LEG-REIMPORT',
        'name' => 'Reimport Member',
        'email' => 'reimport-member@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(5),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 2000,
        'amount_requested' => 2000,
        'amount_approved' => 2000,
        'amount_disbursed' => 2000,
        'interest_rate' => 0,
        'term_months' => 2,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2020-01-01',
        'approved_at' => '2020-01-01',
        'applied_at' => '2020-01-01',
        'installments_count' => 2,
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 100_000]);
    Account::masterFund()->update(['balance' => 100_000]);

    foreach ([1, 2] as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 1000,
            'due_date' => sprintf('2020-0%d-01', $number),
            'status' => 'pending',
        ]);
    }

    $classifiedPath = storage_path('app/legacy-reimport-preserve-test.csv');
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'loan_number',
        'period',
        'notes',
    ], [
        ['', 'LEG-REIMPORT', '2020-02-01', '1000', 'loan_repayment', (string) $loan->id, '', ''],
    ]);

    $before = file_get_contents($classifiedPath);

    app(LegacyPaymentImportService::class)->import($classifiedPath);
    app(LegacyPaymentImportService::class)->import($classifiedPath);

    expect(file_get_contents($classifiedPath))->toBe($before);

    @unlink($classifiedPath);
});

test('legacy payment import merges additional payments into duplicate contribution cycles', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'LEG-FUTURE-CONTRIB',
        'name' => 'Future Contribution Member',
        'email' => 'future-contrib@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(5),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 100_000]);
    Account::masterFund()->update(['balance' => 100_000]);

    $classifiedPath = storage_path('app/legacy-future-contribution-test.csv');
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'loan_number',
        'period',
        'notes',
    ], [
        ['', 'LEG-FUTURE-CONTRIB', '2024-01-15', '1000', 'contribution', '', '2024-01', 'First payment'],
        ['', 'LEG-FUTURE-CONTRIB', '2024-01-20', '500', 'contribution', '', '2024-01', 'Second same month'],
    ]);

    $first = app(LegacyPaymentImportService::class)->import($classifiedPath);
    $second = app(LegacyPaymentImportService::class)->import($classifiedPath);

    $contributions = Contribution::query()->where('member_id', $member->id)->orderBy('period')->get();

    expect($first['contributions'])->toBe(2)
        ->and($first['future_contributions'])->toBe(0)
        ->and($second['contributions'])->toBe(0)
        ->and($second['future_contributions'])->toBe(0)
        ->and($contributions)->toHaveCount(1)
        ->and($contributions[0]->period->format('Y-m'))->toBe('2024-01')
        ->and((float) $contributions[0]->amount)->toBe(1500.0)
        ->and((float) $contributions[0]->amount_collected)->toBe(1500.0)
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(0.0)
        ->and((float) $member->fresh()->fundAccount->balance)->toBe(1500.0);

    @unlink($classifiedPath);
});

test('legacy cash supplement repair merges orphan credits into contribution periods', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'LEG-CASH-REPAIR',
        'name' => 'Cash Supplement Repair Member',
        'email' => 'cash-supplement-repair@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(5),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 100_000]);
    Account::masterFund()->update(['balance' => 100_000]);

    $classifiedPath = storage_path('app/legacy-cash-supplement-repair.csv');
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'loan_number',
        'period',
        'notes',
    ], [
        ['', 'LEG-CASH-REPAIR', '2024-01-15', '1000', 'contribution', '', '2024-01', 'First payment'],
    ]);

    app(LegacyPaymentImportService::class)->import($classifiedPath);

    $contribution = Contribution::findForMemberPeriod($member->id, 1, 2024);

    expect($contribution)->not->toBeNull()
        ->and((float) $contribution->amount)->toBe(1000.0);

    $orphanDescription = __('Legacy migration cash — :period', ['period' => 'Jan 2024'])
        .' [legacy-import:LEG-CASH-REPAIR||2024-01-20|500|contribution|2024-01]';

    AccountingService::withoutMemberCashCollection(function () use ($member, $orphanDescription): void {
        app(AccountingService::class)->creditMemberCashWithMasterMirror(
            $member->cashAccount,
            500,
            $orphanDescription,
            '',
            null,
            now()->parse('2024-01-20'),
            $member->id,
        );
    });

    expect((float) $member->fresh()->cashAccount->balance)->toBe(500.0);

    $result = app(LegacyMigrationCashSupplementRepairService::class)->repairAll();

    expect($result['repaired'])->toBe(1)
        ->and($result['errors'])->toBe([])
        ->and((float) $member->fresh()->cashAccount->balance)->toBe(0.0)
        ->and((float) $member->fresh()->fundAccount->balance)->toBe(1500.0)
        ->and((float) $contribution->fresh()->amount)->toBe(1500.0);

    @unlink($classifiedPath);
});

test('legacy schedule sync allocates repayments on latest loan across member loans in disbursement order', function () {
    $member = Member::create([
        'member_number' => 'LEG-MULTI-LOAN',
        'name' => 'Multi Loan Member',
        'email' => 'multi-loan@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $olderLoan = Loan::create([
        'member_id' => $member->id,
        'amount' => 4000,
        'amount_requested' => 4000,
        'amount_approved' => 4000,
        'amount_disbursed' => 4000,
        'interest_rate' => 0,
        'term_months' => 2,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2016-01-01',
        'approved_at' => '2016-01-01',
        'applied_at' => '2016-01-01',
        'installments_count' => 2,
    ]);

    $newerLoan = Loan::create([
        'member_id' => $member->id,
        'amount' => 2000,
        'amount_requested' => 2000,
        'amount_approved' => 2000,
        'amount_disbursed' => 2000,
        'interest_rate' => 0,
        'term_months' => 2,
        'monthly_repayment' => 1000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2018-01-01',
        'approved_at' => '2018-01-01',
        'applied_at' => '2018-01-01',
        'installments_count' => 2,
    ]);

    foreach ([$olderLoan, $newerLoan] as $loan) {
        foreach ([1, 2] as $number) {
            LoanInstallment::create([
                'loan_id' => $loan->id,
                'installment_number' => $number,
                'amount' => 1000,
                'due_date' => now()->subMonths(3 - $number)->toDateString(),
                'status' => 'pending',
            ]);
        }
    }

    $olderLoan->repayments()->create([
        'amount' => 2000,
        'paid_at' => '2016-06-01',
        'notes' => 'Legacy repayment on older loan',
    ]);

    app(LegacyImportedLoanScheduleSyncService::class)->syncMemberLoans($member);

    expect($olderLoan->installments()->where('status', 'paid')->count())->toBe(2)
        ->and($newerLoan->installments()->where('status', 'paid')->count())->toBe(0)
        ->and($olderLoan->fresh()->status)->toBe('completed')
        ->and($newerLoan->fresh()->status)->toBe('active');
});

test('legacy schedule sync applies repayments to their own loan without bleeding into other loans', function () {
    $member = Member::create([
        'member_number' => 'LEG-SYNC-SCOPED',
        'name' => 'Scoped Sync Member',
        'email' => 'scoped-sync@fund.test',
        'monthly_contribution_amount' => 2000,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $olderLoan = Loan::create([
        'member_id' => $member->id,
        'amount' => 72_000,
        'amount_requested' => 72_000,
        'amount_approved' => 72_000,
        'amount_disbursed' => 72_000,
        'interest_rate' => 0,
        'term_months' => 24,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2016-01-01',
        'approved_at' => '2016-01-01',
        'applied_at' => '2016-01-01',
        'installments_count' => 24,
    ]);

    $newerLoan = Loan::create([
        'member_id' => $member->id,
        'amount' => 72_000,
        'amount_requested' => 72_000,
        'amount_approved' => 72_000,
        'amount_disbursed' => 72_000,
        'interest_rate' => 0,
        'term_months' => 24,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2024-09-29',
        'approved_at' => '2024-09-29',
        'applied_at' => '2024-09-29',
        'installments_count' => 24,
    ]);

    foreach ([$olderLoan, $newerLoan] as $loan) {
        for ($number = 1; $number <= 24; $number++) {
            LoanInstallment::create([
                'loan_id' => $loan->id,
                'installment_number' => $number,
                'amount' => 2000,
                'due_date' => now()->subMonths(24 - $number)->toDateString(),
                'status' => 'pending',
            ]);
        }
    }

    $newerLoan->repayments()->createMany([
        ['amount' => 2500, 'paid_at' => '2024-10-01'],
        ['amount' => 2500, 'paid_at' => '2024-10-28'],
        ['amount' => 2500, 'paid_at' => '2024-12-01'],
        ['amount' => 2000, 'paid_at' => '2024-12-24'],
        ['amount' => 2000, 'paid_at' => '2025-01-30'],
        ['amount' => 2000, 'paid_at' => '2025-03-01'],
        ['amount' => 2000, 'paid_at' => '2025-03-27'],
        ['amount' => 2000, 'paid_at' => '2025-05-01'],
        ['amount' => 2000, 'paid_at' => '2025-06-01'],
        ['amount' => 2000, 'paid_at' => '2025-07-03'],
        ['amount' => 2000, 'paid_at' => '2025-07-29'],
        ['amount' => 2000, 'paid_at' => '2025-08-26'],
        ['amount' => 2000, 'paid_at' => '2025-09-27'],
        ['amount' => 2000, 'paid_at' => '2025-10-30'],
    ]);

    app(LegacyImportedLoanScheduleSyncService::class)->syncMemberLoans($member);

    expect($newerLoan->installments()->where('status', 'paid')->count())->toBe(14)
        ->and($newerLoan->installments()->where('status', 'pending')->count())->toBe(10)
        ->and($newerLoan->fresh()->status)->toBe('active')
        ->and($olderLoan->installments()->where('status', 'paid')->count())->toBe(0)
        ->and($olderLoan->fresh()->status)->toBe('active');
});

test('legacy payment import posts loan repayments for already-imported contribution rows in repayment windows', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'LEG-CONTRIB-REPAY',
        'name' => 'Contribution Repay Member',
        'email' => 'contrib-repay@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 2000)->first()
        ?? LoanTier::create([
            'tier_number' => 101,
            'label' => 'Contrib repay tier',
            'min_amount' => 50_000,
            'max_amount' => 100_000,
            'min_monthly_installment' => 2000,
            'is_active' => true,
        ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 80_000,
        'amount_requested' => 80_000,
        'amount_approved' => 80_000,
        'amount_disbursed' => 80_000,
        'interest_rate' => 0,
        'term_months' => 24,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2017-06-14',
        'approved_at' => '2017-06-14',
        'applied_at' => '2017-06-14',
        'installments_count' => 24,
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 2000,
        'due_date' => '2017-08-05',
        'status' => 'pending',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 500_000]);
    Account::masterFund()->update(['balance' => 500_000]);

    $classifiedPath = storage_path('app/legacy-contrib-repay-test.csv');
    AssociativeCsv::write($classifiedPath, [
        'member_email',
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
        'loan_number',
        'period',
        'notes',
    ], [
        ['', 'LEG-CONTRIB-REPAY', '2017-07-02', '2000', 'loan_repayment', (string) $loan->id, '', 'Loan repayment in open window'],
    ]);

    $first = app(LegacyPaymentImportService::class)->import($classifiedPath);

    expect($first['loan_repayments'])->toBe(1)
        ->and(LoanRepayment::query()->where('loan_id', $loan->id)->count())->toBe(1);

    LoanRepayment::query()->where('loan_id', $loan->id)->delete();

    $second = app(LegacyPaymentImportService::class)->import($classifiedPath);

    expect($second['loan_repayments'])->toBe(1)
        ->and(LoanRepayment::query()->where('loan_id', $loan->id)->count())->toBe(1);

    @unlink($classifiedPath);
});

test('legacy schedule sync completes loans when repayment target is met and reroutes overflow repayments', function () {
    $member = Member::create([
        'member_number' => 'LEG-TARGET-SYNC',
        'name' => 'Target Sync Member',
        'email' => 'target-sync@fund.test',
        'monthly_contribution_amount' => 2000,
        'joined_at' => now()->subYears(15),
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 2000)->first()
        ?? LoanTier::create([
            'tier_number' => 102,
            'label' => 'Target sync tier',
            'min_amount' => 50_000,
            'max_amount' => 120_000,
            'min_monthly_installment' => 2000,
            'is_active' => true,
        ]);

    $olderLoan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 80_000,
        'amount_requested' => 80_000,
        'amount_approved' => 80_000,
        'amount_disbursed' => 80_000,
        'interest_rate' => 0,
        'term_months' => 27,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2017-06-14',
        'approved_at' => '2017-06-14',
        'applied_at' => '2017-06-14',
        'installments_count' => 27,
        'master_portion' => 52_800,
    ]);

    $newerLoan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 72_000,
        'amount_requested' => 72_000,
        'amount_approved' => 72_000,
        'amount_disbursed' => 72_000,
        'interest_rate' => 0,
        'term_months' => 24,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2024-09-29',
        'approved_at' => '2024-09-29',
        'applied_at' => '2024-09-29',
        'installments_count' => 24,
    ]);

    foreach (range(1, 27) as $number) {
        LoanInstallment::create([
            'loan_id' => $olderLoan->id,
            'installment_number' => $number,
            'amount' => 2000,
            'due_date' => Carbon::parse('2017-08-05')->addMonths($number - 1)->toDateString(),
            'status' => 'pending',
        ]);
    }

    foreach (range(1, 5) as $number) {
        LoanInstallment::create([
            'loan_id' => $newerLoan->id,
            'installment_number' => $number,
            'amount' => 2000,
            'due_date' => Carbon::parse('2024-11-05')->addMonths($number - 1)->toDateString(),
            'status' => 'pending',
        ]);
    }

    $target = LegacyLoanRepaymentTarget::forLoan($olderLoan);

    $olderLoan->repayments()->createMany([
        ['amount' => 2000, 'paid_at' => '2017-07-02'],
        ['amount' => 2000, 'paid_at' => '2017-08-28'],
    ]);

    for ($i = 0; $i < (int) (($target - 4000) / 2000); $i++) {
        $olderLoan->repayments()->create([
            'amount' => 2000,
            'paid_at' => Carbon::parse('2017-09-28')->addMonths($i)->toDateString(),
        ]);
    }

    $olderLoan->repayments()->create([
        'amount' => 2500,
        'paid_at' => '2024-04-01',
    ]);

    $newerLoan->repayments()->createMany([
        ['amount' => 2500, 'paid_at' => '2024-10-01'],
        ['amount' => 2500, 'paid_at' => '2024-10-28'],
    ]);

    app(LegacyImportedLoanScheduleSyncService::class)->syncMemberLoans($member);

    $olderLoan->refresh();
    $newerLoan->refresh();

    expect($olderLoan->status)->toBe('completed')
        ->and($olderLoan->installments()->where('status', 'paid')->count())->toBe(27)
        ->and($newerLoan->installments()->where('status', 'paid')->count())->toBeGreaterThan(0)
        ->and(Carbon::parse((string) $olderLoan->installments()->where('status', 'paid')->orderBy('installment_number')->value('paid_at'))->toDateString())
        ->toBe('2017-07-02');
});

test('legacy schedule sync completes loan when repayment target is met via sub-installment payments', function () {
    $member = Member::create([
        'member_number' => 'LEG-SUB-EMI-SYNC',
        'name' => 'Sub EMI Sync Member',
        'email' => 'sub-emi-sync@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(5),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 6000,
        'amount_requested' => 6000,
        'amount_approved' => 6000,
        'amount_disbursed' => 6000,
        'interest_rate' => 0,
        'term_months' => 6,
        'monthly_repayment' => 0,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2023-04-10',
        'approved_at' => '2023-04-10',
        'applied_at' => '2023-04-10',
        'installments_count' => 6,
        'first_repayment_month' => 5,
        'first_repayment_year' => 2023,
        'grace_cycles' => 0,
        'master_portion' => 3960,
    ]);

    foreach (range(1, 6) as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 1000,
            'due_date' => Carbon::parse('2023-05-05')->addMonths($number - 1)->toDateString(),
            'status' => 'pending',
        ]);
    }

    $target = LegacyLoanRepaymentTarget::forLoan($loan);

    foreach ([
        '2023-05-28',
        '2023-06-25',
        '2023-08-03',
        '2023-09-04',
        '2023-10-01',
        '2023-10-27',
        '2024-01-03',
    ] as $paidAt) {
        $loan->repayments()->create([
            'amount' => 500,
            'paid_at' => $paidAt,
        ]);
    }

    $loan->repayments()->create([
        'amount' => round($target - (7 * 500), 2),
        'paid_at' => '2024-03-04',
    ]);

    app(LegacyImportedLoanScheduleSyncService::class)->syncMemberLoans($member);

    $loan->refresh();

    expect($loan->status)->toBe('completed')
        ->and((float) $loan->repayments()->sum('amount'))->toBe($target)
        ->and($loan->installments()->where('status', 'paid')->count())->toBe(6);
});

test('legacy schedule sync accumulates sub-minimum repayments across payments for installment settlement', function () {
    $member = Member::create([
        'member_number' => 'LEG-41-PARTIAL',
        'name' => 'Partial EMI Accumulation Member',
        'email' => 'partial-accum@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 2000)->first()
        ?? LoanTier::create([
            'tier_number' => 98,
            'label' => 'Partial accumulation tier',
            'min_amount' => 50_000,
            'max_amount' => 100_000,
            'min_monthly_installment' => 2000,
            'is_active' => true,
        ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 90_000,
        'amount_requested' => 90_000,
        'amount_approved' => 90_000,
        'amount_disbursed' => 90_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2017-06-14',
        'approved_at' => '2017-06-14',
        'applied_at' => '2017-06-14',
        'installments_count' => 10,
        'first_repayment_month' => 8,
        'first_repayment_year' => 2017,
    ]);

    foreach (range(1, 10) as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 2000,
            'due_date' => Carbon::parse('2017-08-05')->addMonths($number - 1)->toDateString(),
            'status' => 'pending',
        ]);
    }

    foreach ([
        ['amount' => 4000, 'paid_at' => '2017-08-30'],
        ['amount' => 4000, 'paid_at' => '2017-11-05'],
        ['amount' => 2000, 'paid_at' => '2017-12-06'],
        ['amount' => 4000, 'paid_at' => '2018-02-06'],
        ['amount' => 1500, 'paid_at' => '2018-03-06'],
        ['amount' => 2000, 'paid_at' => '2018-03-22'],
        ['amount' => 1500, 'paid_at' => '2018-04-25'],
    ] as $repayment) {
        $loan->repayments()->create($repayment);
    }

    app(LegacyImportedLoanScheduleSyncService::class)->syncMemberLoans($member);

    $paidAtByNumber = $loan->installments()
        ->where('status', 'paid')
        ->orderBy('installment_number')
        ->pluck('paid_at', 'installment_number')
        ->map(fn ($paidAt) => Carbon::parse((string) $paidAt)->toDateString());

    expect($loan->installments()->where('status', 'paid')->count())->toBe(9)
        ->and($paidAtByNumber[8])->toBe('2018-03-22')
        ->and($paidAtByNumber[9])->toBe('2018-04-25');
});

test('payment classifier routes post-schedule repayments to contributions after final installment is covered', function () {
    $member = Member::create([
        'member_number' => '14',
        'name' => 'Loan 41 Classifier Member',
        'email' => 'loan41-classifier@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => '2014-11-09',
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 2000)->first()
        ?? LoanTier::create([
            'tier_number' => 97,
            'label' => 'Loan 41 classifier tier',
            'min_amount' => 50_000,
            'max_amount' => 100_000,
            'min_monthly_installment' => 2000,
            'is_active' => true,
        ]);

    $loan = new Loan([
        'member_id' => $member->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 90_000,
        'amount_requested' => 90_000,
        'amount_approved' => 90_000,
        'amount_disbursed' => 90_000,
        'interest_rate' => 0,
        'term_months' => 22,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'status' => 'active',
        'disbursed_at' => '2017-06-14',
        'approved_at' => '2017-06-14',
        'applied_at' => '2017-06-14',
        'installments_count' => 22,
        'first_repayment_month' => 8,
        'first_repayment_year' => 2017,
    ]);
    $loan->id = 41;
    $loan->save();

    foreach (range(1, 22) as $number) {
        LoanInstallment::create([
            'loan_id' => $loan->id,
            'installment_number' => $number,
            'amount' => 2000,
            'due_date' => Carbon::parse('2017-08-05')->addMonths($number - 1)->toDateString(),
            'status' => 'pending',
        ]);
    }

    $membersPath = storage_path('app/loan41-classifier-members.csv');
    $loansPath = storage_path('app/loan41-classifier-loans.csv');
    $paymentsPath = storage_path('app/loan41-classifier-payments.csv');

    AssociativeCsv::write($membersPath, [
        'member_number',
        'name',
        'email',
        'monthly_contribution_amount',
        'joined_at',
    ], [
        ['14', $member->name, $member->email, '500', '2014-11-09'],
    ]);

    AssociativeCsv::write($loansPath, [
        'loan_id',
        'loan_status',
        'member_number',
        'amount_approved',
        'disbursed_at',
    ], [
        ['41', 'active', '14', '90000', '6/14/2017'],
    ]);

    $paymentRows = [
        ['14', '2017-08-30', '4000'],
        ['14', '2017-11-05', '4000'],
        ['14', '2017-12-06', '2000'],
        ['14', '2018-02-06', '4000'],
        ['14', '2018-03-06', '1500'],
        ['14', '2018-03-22', '2000'],
        ['14', '2018-04-25', '1500'],
        ['14', '2018-07-08', '4000'],
        ['14', '2018-07-22', '2000'],
        ['14', '2018-09-03', '2000'],
        ['14', '2018-10-02', '2000'],
        ['14', '2018-10-22', '2000'],
        ['14', '2018-11-13', '2000'],
        ['14', '2019-03-03', '6000'],
        ['14', '2019-04-02', '2000'],
        ['14', '2019-05-30', '2000'],
        ['14', '2019-07-03', '2000'],
        ['14', '2019-08-05', '5000'],
        ['14', '2019-09-05', '2000'],
        ['14', '2019-10-06', '2000'],
        ['14', '2019-10-30', '2000'],
        ['14', '2019-11-29', '2000'],
        ['14', '2019-12-06', '2000'],
    ];

    AssociativeCsv::write($paymentsPath, [
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
    ], array_map(
        fn (array $row): array => [$row[0], $row[1], $row[2], ''],
        $paymentRows,
    ));

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
        $membersPath,
        $loansPath,
    );

    $rowsByDate = collect($result['rows'])->keyBy('payment_date');

    expect($rowsByDate['2019-07-03']['payment_type'])->toBe('loan_repayment')
        ->and($rowsByDate['2019-07-03']['loan_number'])->toBe('41')
        ->and($rowsByDate['2019-08-05']['payment_type'])->toBe('contribution')
        ->and($rowsByDate['2019-09-05']['payment_type'])->toBe('contribution')
        ->and($rowsByDate['2019-10-06']['payment_type'])->toBe('contribution')
        ->and($rowsByDate['2019-10-30']['payment_type'])->toBe('contribution')
        ->and($rowsByDate['2019-11-29']['payment_type'])->toBe('contribution')
        ->and($rowsByDate['2019-12-06']['payment_type'])->toBe('contribution');

    @unlink($membersPath);
    @unlink($loansPath);
    @unlink($paymentsPath);
});

test('payment classifier routes post-schedule repayments using csv schedule estimate when loan is not in database', function () {
    $membersPath = storage_path('app/loan41-csv-only-members.csv');
    $loansPath = storage_path('app/loan41-csv-only-loans.csv');
    $paymentsPath = storage_path('app/loan41-csv-only-payments.csv');

    AssociativeCsv::write($membersPath, [
        'member_number',
        'name',
        'email',
        'monthly_contribution_amount',
        'joined_at',
    ], [
        ['14', 'CSV Only Member', 'csv-only-loan41@fund.test', '500', '2014-11-09'],
    ]);

    AssociativeCsv::write($loansPath, [
        'loan_id',
        'loan_status',
        'member_number',
        'amount_approved',
        'disbursed_at',
    ], [
        ['41', 'active', '14', '90000', '6/14/2017'],
    ]);

    $paymentRows = [
        ['14', '2017-08-30', '4000'],
        ['14', '2017-11-05', '4000'],
        ['14', '2017-12-06', '2000'],
        ['14', '2018-02-06', '4000'],
        ['14', '2018-03-06', '1500'],
        ['14', '2018-03-22', '2000'],
        ['14', '2018-04-25', '1500'],
        ['14', '2018-07-08', '4000'],
        ['14', '2018-07-22', '2000'],
        ['14', '2018-09-03', '2000'],
        ['14', '2018-10-02', '2000'],
        ['14', '2018-10-22', '2000'],
        ['14', '2018-11-13', '2000'],
        ['14', '2019-03-03', '6000'],
        ['14', '2019-04-02', '2000'],
        ['14', '2019-05-30', '2000'],
        ['14', '2019-07-03', '2000'],
        ['14', '2019-08-05', '5000'],
        ['14', '2019-09-05', '2000'],
    ];

    AssociativeCsv::write($paymentsPath, [
        'member_number',
        'payment_date',
        'amount',
        'payment_type',
    ], array_map(
        fn (array $row): array => [$row[0], $row[1], $row[2], ''],
        $paymentRows,
    ));

    expect(Loan::query()->find(41))->toBeNull();

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $paymentsPath,
        now()->parse('2025-12-31'),
        $membersPath,
        $loansPath,
    );

    $rowsByDate = collect($result['rows'])->keyBy('payment_date');

    expect($rowsByDate['2019-07-03']['payment_type'])->toBe('loan_repayment')
        ->and($rowsByDate['2019-08-05']['payment_type'])->toBe('contribution')
        ->and($rowsByDate['2019-09-05']['payment_type'])->toBe('contribution');

    @unlink($membersPath);
    @unlink($loansPath);
    @unlink($paymentsPath);
});

test('legacy payment import never overwrites canonical classified payments file', function () {
    $this->actingAs($this->admin, 'tenant');

    $legacyLoanId = 94;

    $member = Member::create([
        'member_number' => '58',
        'name' => 'Canonical Guard Member',
        'email' => 'canonical-guard@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => '2019-01-04',
        'status' => 'active',
    ]);

    $membersPath = storage_path('app/canonical-guard-members.csv');
    $loansPath = storage_path('app/canonical-guard-loans.csv');
    $paymentsPath = storage_path('app/canonical-guard-payments.csv');

    AssociativeCsv::write($membersPath, [
        'member_number',
        'name',
        'email',
        'monthly_contribution_amount',
        'cutoff_cash_balance',
        'cutoff_fund_balance',
    ], [
        ['58', 'Canonical Guard Member', 'canonical-guard@fund.test', '500', '0', '0'],
    ]);
    AssociativeCsv::write($loansPath, ['loan_id', 'loan_status', 'member_number', 'amount_approved', 'disbursed_at'], [
        [(string) $legacyLoanId, 'active', '58', '20000', '2020-07-10'],
    ]);
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], [
        ['58', '2020-07-29', '1000'],
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()?->update(['balance' => 500_000]);
    Account::masterFund()?->update(['balance' => 500_000]);

    app(LoanImportService::class)->import($loansPath, 0);

    $canonicalPath = Storage::disk('local')->path(LegacyPaymentClassifierService::CLASSIFIED_PAYMENTS_DISK_PATH);

    app(LegacyMigrationOrchestrator::class)->classifyAndPersistPayments([
        'members_path' => $membersPath,
        'loans_path' => $loansPath,
        'payments_path' => $paymentsPath,
        'grace_cycles' => 0,
    ]);

    $before = AssociativeCsv::read($canonicalPath);

    expect(collect($before)->firstWhere('member_number', '58')['payment_type'] ?? '')->toBe('loan_repayment');

    app(LegacyPaymentImportService::class)->import(
        $canonicalPath,
        $loansPath,
        0,
    );

    $after = AssociativeCsv::read($canonicalPath);

    expect($after)->toBe($before);

    @unlink($membersPath);
    @unlink($loansPath);
    @unlink($paymentsPath);
    Storage::disk('local')->delete(LegacyPaymentClassifierService::CLASSIFIED_PAYMENTS_DISK_PATH);
});

test('historical payment apply posts loan repayment from classified file after classification', function () {
    $this->actingAs($this->admin, 'tenant');

    $legacyLoanId = 94;

    $member = Member::create([
        'member_number' => '58',
        'name' => 'Jul Window Regression Member',
        'email' => 'jul-regression@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => '2019-01-04',
        'status' => 'active',
    ]);

    $loanTier = LoanTier::query()->where('min_monthly_installment', 1000)->first()
        ?? LoanTier::create([
            'tier_number' => 104,
            'label' => 'Jul regression tier',
            'min_amount' => 10_000,
            'max_amount' => 30_000,
            'min_monthly_installment' => 1000,
            'is_active' => true,
        ]);

    $membersPath = storage_path('app/jul-regression-members.csv');
    $loansPath = storage_path('app/jul-regression-loans.csv');
    $paymentsPath = storage_path('app/jul-regression-payments.csv');
    $classifiedPath = Storage::disk('local')->path('legacy-migration/last-classified-payments.csv');

    AssociativeCsv::write($membersPath, [
        'member_number',
        'name',
        'email',
        'monthly_contribution_amount',
        'cutoff_cash_balance',
        'cutoff_fund_balance',
    ], [
        ['58', 'Jul Window Regression Member', 'jul-regression@fund.test', '500', '0', '0'],
    ]);
    AssociativeCsv::write($loansPath, ['loan_id', 'loan_status', 'member_number', 'amount_approved', 'disbursed_at'], [
        [(string) $legacyLoanId, 'active', '58', '20000', '2020-07-10'],
    ]);
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], [
        ['58', '2020-07-29', '1000'],
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()?->update(['balance' => 500_000]);
    Account::masterFund()?->update(['balance' => 500_000]);

    app(LoanImportService::class)->import($loansPath, 0);

    expect(Loan::query()->find($legacyLoanId))->not->toBeNull();

    app(LegacyMigrationOrchestrator::class)->classifyAndPersistPayments([
        'strategy' => 'historical',
        'members_path' => $membersPath,
        'loans_path' => $loansPath,
        'payments_path' => $paymentsPath,
        'default_password' => 'password123',
        'grace_cycles' => 0,
    ]);

    $result = app(LegacyMigrationOrchestrator::class)->applyClassifiedPayments([
        'strategy' => 'historical',
        'classified_payments_path' => $classifiedPath,
        'loans_path' => $loansPath,
        'grace_cycles' => 0,
    ]);

    expect($result['errors'] ?? [])->toBe([])
        ->and($result['loan_repayments'] ?? 0)->toBe(1)
        ->and($result['classification']['contributions'] ?? 0)->toBe(0);

    $classifiedRows = AssociativeCsv::read($classifiedPath);
    $julRow = collect($classifiedRows)->first(
        fn (array $row): bool => ($row['member_number'] ?? '') === '58'
        && ($row['payment_date'] ?? '') === '2020-07-29',
    );

    expect($julRow)->not->toBeNull()
        ->and($julRow['payment_type'] ?? '')->toBe('loan_repayment')
        ->and($julRow['loan_number'] ?? '')->toBe((string) $legacyLoanId)
        ->and(LoanRepayment::query()->where('loan_id', $legacyLoanId)->whereDate('paid_at', '2020-07-29')->count())->toBe(1);

    @unlink($membersPath);
    @unlink($loansPath);
    @unlink($paymentsPath);
    Storage::disk('local')->delete(LegacyPaymentClassifierService::CLASSIFIED_PAYMENTS_DISK_PATH);
});

test('legacy payment import posts full loan repayment amounts from classified blueprint', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'LEG-FUND-PORTION',
        'name' => 'Fund Portion Member',
        'email' => 'fund-portion@fund.test',
        'monthly_contribution_amount' => 3000,
        'joined_at' => now()->subYears(12),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 500_000]);
    Account::masterFund()->update(['balance' => 500_000]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 150_000,
        'amount_requested' => 150_000,
        'amount_approved' => 150_000,
        'amount_disbursed' => 150_000,
        'interest_rate' => 0,
        'term_months' => 27,
        'monthly_repayment' => 3000,
        'total_repaid' => 0,
        'master_portion' => 79_500,
        'member_portion' => 70_500,
        'settlement_threshold' => 0,
        'status' => 'active',
        'disbursed_at' => '2016-02-09',
        'approved_at' => '2016-02-09',
        'applied_at' => '2016-02-09',
        'installments_count' => 27,
    ]);

    $paymentDates = [
        '2016-02-10', '2016-03-12', '2016-03-25', '2016-05-09', '2016-06-08', '2016-06-29',
        '2016-08-06', '2016-09-04', '2016-09-30', '2016-11-05', '2016-12-05', '2017-01-05',
        '2017-02-04', '2017-03-03', '2017-03-31', '2017-05-02', '2017-06-03', '2017-07-05',
        '2017-08-04', '2017-08-29', '2017-10-06', '2017-11-04', '2017-12-04', '2018-01-05',
        '2018-02-02', '2018-03-05', '2018-04-02', '2018-05-04',
    ];

    $classifiedPath = storage_path('app/legacy-fund-portion-import-test.csv');
    AssociativeCsv::write(
        $classifiedPath,
        ['member_email', 'member_number', 'payment_date', 'amount', 'payment_type', 'loan_number', 'period', 'notes'],
        collect($paymentDates)->map(fn (string $date): array => [
            'member_email' => 'fund-portion@fund.test',
            'member_number' => 'LEG-FUND-PORTION',
            'payment_date' => $date,
            'amount' => $date === '2016-02-10' ? '4500' : '3000',
            'payment_type' => 'loan_repayment',
            'loan_number' => (string) $loan->id,
            'period' => '',
            'notes' => '',
        ])->all(),
    );

    $result = app(LegacyPaymentImportService::class)->import($classifiedPath);

    $repaymentSum = (float) LoanRepayment::query()->where('loan_id', $loan->id)->sum('amount');
    $expectedSum = collect($paymentDates)->sum(fn (string $date): float => $date === '2016-02-10' ? 4500.0 : 3000.0);

    expect($result['failed'])->toBe(0)
        ->and($repaymentSum)->toBe($expectedSum)
        ->and(Carbon::parse((string) LoanRepayment::query()->where('loan_id', $loan->id)->orderByDesc('paid_at')->value('paid_at'))->toDateString())
        ->toBe('2018-05-04')
        ->and(Contribution::query()->where('member_id', $member->id)->count())->toBe(0);

    @unlink($classifiedPath);
});

test('legacy excess loan repayment repair moves repayments beyond fund portion to contributions', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'LEG-EXCESS-REPAIR',
        'name' => 'Excess Repair Member',
        'email' => 'excess-repair@fund.test',
        'monthly_contribution_amount' => 3000,
        'joined_at' => now()->subYears(12),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 500_000]);
    Account::masterFund()->update(['balance' => 500_000]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 15_000,
        'amount_requested' => 15_000,
        'amount_approved' => 15_000,
        'amount_disbursed' => 15_000,
        'interest_rate' => 0,
        'term_months' => 5,
        'monthly_repayment' => 3000,
        'total_repaid' => 0,
        'master_portion' => 7_500,
        'member_portion' => 7_500,
        'settlement_threshold' => 0,
        'status' => 'completed',
        'disbursed_at' => '2016-02-09',
        'approved_at' => '2016-02-09',
        'applied_at' => '2016-02-09',
        'installments_count' => 5,
    ]);

    $chronology = [
        ['2016-02-10', 4500],
        ['2016-03-12', 3000],
        ['2016-04-02', 3000],
    ];

    foreach ($chronology as [$date, $amount]) {
        $repayment = $loan->repayments()->create([
            'amount' => $amount,
            'paid_at' => $date,
            'notes' => 'Legacy repayment',
        ]);

        app(LoanLedgerService::class)->postImportedLoanRepaymentWithCashFlow(
            $loan->fresh(),
            $repayment,
            (float) $amount,
            Carbon::parse($date),
        );
    }

    $loan->refresh();

    expect((float) $loan->repayments()->sum('amount'))->toBe(10_500.0)
        ->and((float) $loan->repaid_to_master)->toBe(7_500.0);

    $stats = app(LegacyExcessLoanRepaymentRepairService::class)->repairLoan($loan->fresh());

    $loan->refresh();

    expect($stats['repayments_reversed'])->toBe(1)
        ->and((float) $loan->repayments()->sum('amount'))->toBe(7_500.0)
        ->and((float) $loan->repaid_to_master)->toBe(7_500.0);
});

test('legacy excess loan repayment repair reverses all repayments and completes fully member-funded loans', function () {
    Notification::fake();

    $member = Member::create([
        'member_number' => 'LEG-FUND-ONLY-REPAIR',
        'name' => 'Fund Only Repair Member',
        'email' => 'fund-only-repair@fund.test',
        'monthly_contribution_amount' => 2000,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    Account::masterCash()->update(['balance' => 500_000]);
    Account::masterFund()->update(['balance' => 500_000]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 20_000,
        'amount_requested' => 20_000,
        'amount_approved' => 20_000,
        'amount_disbursed' => 20_000,
        'interest_rate' => 0,
        'term_months' => 6,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'member_portion' => 20_000,
        'master_portion' => 0,
        'settlement_threshold' => 0,
        'status' => 'active',
        'disbursed_at' => '2016-11-28',
        'approved_at' => '2016-11-28',
        'applied_at' => '2016-11-28',
        'installments_count' => 6,
    ]);

    foreach ([
        ['2016-11-28', 2000],
        ['2017-01-05', 2000],
        ['2017-02-04', 2000],
    ] as [$date, $amount]) {
        $repayment = $loan->repayments()->create([
            'amount' => $amount,
            'paid_at' => $date,
            'notes' => 'Misclassified legacy repayment',
        ]);

        app(LoanLedgerService::class)->postImportedLoanRepaymentWithCashFlow(
            $loan->fresh(),
            $repayment,
            (float) $amount,
            Carbon::parse($date),
        );
    }

    $stats = app(LegacyExcessLoanRepaymentRepairService::class)->repairLoan($loan->fresh());

    $loan->refresh();

    expect(LegacyLoanRepaymentTarget::forLoan($loan))->toBe(0.0)
        ->and($stats['repayments_reversed'])->toBe(3)
        ->and($loan->repayments()->count())->toBe(0)
        ->and($loan->status)->toBe('completed')
        ->and($loan->installments_count)->toBe(0);
});

test('legacy zero balance loan completion marks fully paid active loans as completed', function () {
    $member = Member::create([
        'member_number' => 'LEG-ZERO-BAL',
        'name' => 'Zero Balance Member',
        'email' => 'legacy-zero-balance@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(3),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
        'interest_rate' => 0,
        'term_months' => 1,
        'monthly_repayment' => 5000,
        'total_repaid' => 5000,
        'member_portion' => 5000,
        'master_portion' => 0,
        'settlement_threshold' => 0.16,
        'status' => 'active',
        'disbursed_at' => '2022-01-10',
        'approved_at' => '2022-01-10',
        'applied_at' => '2022-01-10',
        'installments_count' => 1,
    ]);

    LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 1,
        'amount' => 5000,
        'due_date' => '2022-03-05',
        'status' => 'paid',
        'paid_at' => '2022-03-05',
    ]);

    expect($loan->getOutstandingBalance())->toBe(0.0)
        ->and($loan->status)->toBe('active');

    $result = app(LegacyMigrationZeroBalanceLoanCompletionService::class)->completeAll();

    expect($result['completed'])->toBe(1)
        ->and($result['loan_ids'])->toContain($loan->id)
        ->and($loan->fresh()->status)->toBe('completed')
        ->and($loan->fresh()->settled_at)->not->toBeNull();
});

test('legacy zero balance loan completion marks transferred loans with no installments as completed', function () {
    $member = Member::create([
        'member_number' => 'LEG-ZERO-XFER',
        'name' => 'Zero Balance Transferred',
        'email' => 'legacy-zero-xfer@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(4),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 3000,
        'amount_requested' => 3000,
        'amount_approved' => 3000,
        'amount_disbursed' => 3000,
        'interest_rate' => 0,
        'term_months' => 0,
        'monthly_repayment' => 0,
        'total_repaid' => 3000,
        'member_portion' => 3000,
        'master_portion' => 0,
        'settlement_threshold' => 0,
        'status' => 'transferred',
        'disbursed_at' => '2021-06-01',
        'approved_at' => '2021-06-01',
        'applied_at' => '2021-06-01',
        'installments_count' => 0,
    ]);

    $result = app(LegacyMigrationZeroBalanceLoanCompletionService::class)->completeAll();

    expect($result['completed'])->toBe(1)
        ->and($loan->fresh()->status)->toBe('completed');
});

test('legacy zero balance loan completion closes fully member funded loans with no repayment schedule', function () {
    $member = Member::create([
        'member_number' => 'LEG-FUND-COMPLETE',
        'name' => 'Fund Complete Member',
        'email' => 'legacy-fund-complete@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
    ]);

    $loan = Loan::create([
        'member_id' => $member->id,
        'amount' => 8000,
        'amount_requested' => 8000,
        'amount_approved' => 8000,
        'amount_disbursed' => 8000,
        'interest_rate' => 0,
        'term_months' => 0,
        'monthly_repayment' => 0,
        'total_repaid' => 0,
        'member_portion' => 8000,
        'master_portion' => 0,
        'settlement_threshold' => 0,
        'status' => 'active',
        'disbursed_at' => '2020-05-15',
        'approved_at' => '2020-05-15',
        'applied_at' => '2020-05-15',
        'installments_count' => 0,
    ]);

    $result = app(LegacyMigrationZeroBalanceLoanCompletionService::class)->completeAll();

    expect($result['completed'])->toBe(1)
        ->and($loan->fresh()->status)->toBe('completed')
        ->and($loan->fresh()->settled_at?->toDateString())->toBe('2020-05-15');
});

test('legacy schedule sync does not reopen completed fully member funded loans on the same member', function () {
    $member = Member::create([
        'member_number' => 'LEG-FUND-SYNC',
        'name' => 'Fund Sync Member',
        'email' => 'legacy-fund-sync@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(10),
        'status' => 'active',
    ]);

    $fundOnlyLoan = Loan::create([
        'member_id' => $member->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'amount_approved' => 5000,
        'amount_disbursed' => 5000,
        'interest_rate' => 0,
        'term_months' => 0,
        'monthly_repayment' => 0,
        'total_repaid' => 0,
        'member_portion' => 5000,
        'master_portion' => 0,
        'settlement_threshold' => 0,
        'status' => 'completed',
        'disbursed_at' => '2018-01-01',
        'approved_at' => '2018-01-01',
        'applied_at' => '2018-01-01',
        'settled_at' => '2018-01-01',
        'installments_count' => 0,
    ]);

    $scheduledLoan = Loan::create([
        'member_id' => $member->id,
        'amount' => 24_000,
        'amount_requested' => 24_000,
        'amount_approved' => 24_000,
        'amount_disbursed' => 24_000,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 2000,
        'total_repaid' => 0,
        'member_portion' => 12_000,
        'master_portion' => 12_000,
        'settlement_threshold' => 0,
        'status' => 'active',
        'disbursed_at' => '2020-01-01',
        'approved_at' => '2020-01-01',
        'applied_at' => '2020-01-01',
        'installments_count' => 12,
    ]);

    for ($number = 1; $number <= 12; $number++) {
        LoanInstallment::create([
            'loan_id' => $scheduledLoan->id,
            'installment_number' => $number,
            'amount' => 2000,
            'due_date' => now()->parse('2020-01-01')->addMonths($number - 1)->toDateString(),
            'status' => 'pending',
        ]);
    }

    $scheduledLoan->repayments()->create([
        'amount' => 2000,
        'paid_at' => '2020-02-01',
    ]);

    app(LegacyImportedLoanScheduleSyncService::class)->syncMemberLoans($member);

    expect($fundOnlyLoan->fresh()->status)->toBe('completed')
        ->and($fundOnlyLoan->fresh()->settled_at?->toDateString())->toBe('2018-01-01')
        ->and($scheduledLoan->fresh()->status)->toBe('active')
        ->and($scheduledLoan->installments()->where('status', 'paid')->count())->toBe(1);
});

test('legacy loan disbursement portion repair recalculates member fund topup from payments csv', function () {
    $this->actingAs($this->admin, 'tenant');

    LegacyMigrationDateFormatSettings::saveSlashDateFormat(LegacyMigrationDateFormatSettings::SLASH_EUROPEAN);

    Account::masterCash()->update(['balance' => 500_000]);
    Account::masterFund()->update(['balance' => 500_000]);

    $member = Member::create([
        'member_number' => 'REP-13',
        'name' => 'Portion Repair Member',
        'email' => 'portion-repair@fund.test',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYears(6),
        'status' => 'active',
    ]);
    app(AccountingService::class)->createMemberAccounts($member);

    $paymentsPath = storage_path('app/legacy-portion-repair-payments.csv');
    $loansPath = storage_path('app/legacy-portion-repair-loans.csv');

    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], [
        ['REP-13', '03/06/2017', '500'],
        ['REP-13', '26/07/2017', '7000'],
        ['REP-13', '27/08/2017', '1000'],
        ['REP-13', '27/09/2017', '500'],
        ['REP-13', '23/10/2017', '2500'],
    ]);
    AssociativeCsv::write($loansPath, ['loan_id', 'loan_status', 'member_number', 'amount_approved', 'disbursed_at'], [
        ['9001', 'active', 'REP-13', '20000', '12/06/2017'],
    ]);

    app(LoanImportService::class)->import($loansPath, 0, LoanFundingStrategy::MEMBER_FUND_TOPUP);

    $loan = Loan::query()->find(9001);

    expect($loan)->not->toBeNull()
        ->and((float) $loan->member_portion)->toBe(0.0)
        ->and((float) $loan->master_portion)->toBe(20000.0);

    $result = app(LegacyLoanDisbursementPortionRepairService::class)->repairFromPaymentsCsv($paymentsPath);

    $loan->refresh();

    expect($result['repaired'])->toBe(1)
        ->and((float) $loan->member_portion)->toBe(11500.0)
        ->and((float) $loan->master_portion)->toBe(8500.0);

    @unlink($paymentsPath);
    @unlink($loansPath);
});

test('legacy loan funding simulator includes member opening fund balance', function () {
    $this->actingAs($this->admin, 'tenant');

    $member = Member::create([
        'member_number' => 'OPEN-FUND',
        'name' => 'Opening Fund Member',
        'email' => 'opening-fund@fund.test',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(4),
        'status' => 'active',
        'opening_fund_balance' => 2500,
    ]);

    $paymentsPath = storage_path('app/legacy-opening-fund-payments.csv');
    AssociativeCsv::write($paymentsPath, ['member_number', 'payment_date', 'amount'], []);

    $simulator = LegacyMigrationLoanFundingSimulator::forLegacyMigration($paymentsPath);

    expect($simulator->fundBalanceBeforeDisbursement($member, now()->parse('2018-01-01')))->toBe(2500.0);

    @unlink($paymentsPath);
});
