<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\LegacyMigrationPage;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\LegacyMigration\LegacyLoanRepaymentTarget;
use App\Services\LegacyMigration\LegacyMigrationOrchestrator;
use App\Services\LegacyMigration\LegacyMigrationPreviewService;
use App\Services\LegacyMigration\LegacyPaymentClassifierService;
use App\Support\AssociativeCsv;
use App\Support\FilamentStoredUploadPath;
use App\Support\LegacyMigrationDateParser;
use App\Support\LegacyMigrationSampleCsv;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Member::query()->delete();
    User::query()->delete();

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

    Livewire::actingAs($this->admin, 'tenant')
        ->test(LegacyMigrationPage::class)
        ->assertSuccessful()
        ->assertSee(__('Recommended approach'))
        ->assertSee(__('Upload files & settings'));
});

test('legacy migration preview reads stored member csv upload', function () {
    Filament::setCurrentPanel('tenant');

    Storage::disk('local')->put('legacy-migration/preview-members.csv', implode("\n", [
        'member_number,name,email',
        'LEG-PREVIEW,Preview Member,preview-member@fund.test',
    ]));

    Livewire::actingAs($this->admin, 'tenant')
        ->test(LegacyMigrationPage::class)
        ->fillForm([
            'cutoff_date' => '2025-12-31',
            'members_csv' => ['legacy-migration/preview-members.csv'],
        ])
        ->call('previewMigration')
        ->assertNotified(__('Preview ready'));

    Storage::disk('local')->delete('legacy-migration/preview-members.csv');
});

test('legacy migration sample csvs share member identifiers across files', function () {
    $memberNumbers = array_column(LegacyMigrationSampleCsv::memberRows(), 0);
    $memberNames = array_column(LegacyMigrationSampleCsv::memberRows(), 1);

    foreach (LegacyMigrationSampleCsv::loanRows() as $row) {
        [$number, $name] = [$row[1], $row[2]];

        if ($number !== '') {
            expect($number)->toBeIn($memberNumbers);
        } else {
            expect($name)->toBeIn($memberNames);
        }

        $guarantorNumber = $row[10] ?? '';
        $guarantorName = $row[11] ?? '';

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
            fn(string $warning): bool => str_contains($warning, 'member_number'),
        ))->toBeFalse();

    @unlink($path);
});

test('legacy migration preview accepts real legacy members export shape', function () {
    $source = base_path('docs/legacy/legacy-members-import-1.csv');

    if (!is_readable($source)) {
        skip('Sample legacy members CSV is not present in docs/legacy.');
    }

    $preview = app(LegacyMigrationPreviewService::class)->previewMembers($source);

    expect($preview['headers'])->toContain('member_number')
        ->and($preview['row_count'])->toBeGreaterThan(0)
        ->and(collect($preview['warnings'])->contains(
            fn(string $warning): bool => str_contains($warning, 'member_number'),
        ))->toBeFalse();
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

test('legacy migration classify payments writes downloadable classified csv', function () {
    Filament::setCurrentPanel('tenant');

    Storage::disk('local')->put('legacy-migration/classify-members.csv', implode("\n", [
        'member_number,name,email,monthly_contribution_amount',
        '1,Classify Member,classify@fund.test,1000',
    ]));
    Storage::disk('local')->put('legacy-migration/classify-payments.csv', implode("\n", [
        'member_number,payment_date,amount',
        '1,2025-10-01,1000',
    ]));

    Livewire::actingAs($this->admin, 'tenant')
        ->test(LegacyMigrationPage::class)
        ->fillForm([
            'strategy' => 'historical',
            'cutoff_date' => '2025-12-31',
            'members_csv' => ['legacy-migration/classify-members.csv'],
            'payments_csv' => ['legacy-migration/classify-payments.csv'],
        ])
        ->call('classifyPayments')
        ->assertNotified(__('Payments classified'))
        ->assertSet('classifiedPaymentsReady', true)
        ->assertSet('classificationStats.contribution', 1);

    expect(Storage::disk('local')->exists('legacy-migration/last-classified-payments.csv'))->toBeTrue();

    Storage::disk('local')->delete([
        'legacy-migration/classify-members.csv',
        'legacy-migration/classify-payments.csv',
        'legacy-migration/last-classified-payments.csv',
    ]);
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

    if (!is_readable($members) || !is_readable($payments)) {
        skip('Legacy sample CSVs are not present in docs/legacy.');
    }

    $result = app(LegacyPaymentClassifierService::class)->classifyFile(
        $payments,
        now()->parse('2025-12-31'),
        $members,
        is_readable($loans) ? $loans : null,
    );

    $loanRepaymentsIn2014 = collect($result['rows'])
        ->filter(fn(array $row): bool => $row['payment_type'] === 'loan_repayment' && str_starts_with($row['payment_date'], '2014'))
        ->count();

    $memberOneAfterLoan = collect($result['rows'])
        ->filter(fn(array $row): bool => $row['member_number'] === '1' && $row['payment_date'] >= '2016-08-01' && $row['payment_date'] <= '2016-10-31')
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

test('legacy loan repayment target uses fifty fifty allocation plus sixteen percent fee', function () {
    expect(LegacyLoanRepaymentTarget::totalRepaymentDue(100_000))->toBe(66_000.0)
        ->and(LegacyLoanRepaymentTarget::totalRepaymentDue(12_000))->toBe(7_920.0);
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
        ->and($result['stats']['loan_repayment'])->toBe(1)
        ->and($result['stats']['contribution'])->toBe(3);

    @unlink($membersPath);
    @unlink($loansPath);
    @unlink($paymentsPath);
});

test('payment classifier treats post disbursement payments as loan repayments until target is met', function () {
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
        ->and($result['stats']['contribution'])->toBe(2)
        ->and($result['rows'][0]['payment_type'])->toBe('contribution')
        ->and($result['rows'][1]['payment_type'])->toBe('contribution')
        ->and($result['rows'][2]['payment_type'])->toBe('unclassified');

    @unlink($membersPath);
    @unlink($loansPath);
    @unlink($paymentsPath);
});

test('legacy migration date parser preserves iso payment dates', function () {
    expect(LegacyMigrationDateParser::parse('2025-10-01', 2)->toDateString())->toBe('2025-10-01')
        ->and(LegacyMigrationDateParser::parse('8/10/2014', 2)->toDateString())->toBe('2014-10-08')
        ->and(LegacyMigrationDateParser::parse('2/25/2016', 2)->toDateString())->toBe('2016-02-25');
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
