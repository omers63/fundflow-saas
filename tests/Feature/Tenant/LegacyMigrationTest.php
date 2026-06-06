<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\LegacyMigrationPage;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\LegacyMigration\LegacyMigrationOrchestrator;
use App\Services\LegacyMigration\LegacyPaymentClassifierService;
use App\Support\AssociativeCsv;
use Filament\Facades\Filament;
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

test('tenant admin can access legacy migration page', function () {
    Filament::setCurrentPanel('tenant');

    Livewire::actingAs($this->admin, 'tenant')
        ->test(LegacyMigrationPage::class)
        ->assertSuccessful()
        ->assertSee(__('Recommended approach'));
});

test('legacy migration orchestrator dry run validates members csv', function () {
    $path = storage_path('app/legacy-migration-test-members.csv');

    AssociativeCsv::write($path, ['name', 'email', 'cutoff_cash_balance', 'cutoff_fund_balance'], [
        ['name' => 'Legacy Member', 'email' => 'legacy-member@fund.test', 'cutoff_cash_balance' => '100', 'cutoff_fund_balance' => '500'],
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
        'monthly_repayment' => 0,
        'total_repaid' => 0,
        'total_amount_repaid' => 2000,
        'installments_count' => 10,
        'status' => 'active',
        'disbursed_at' => now()->subMonths(3),
        'purpose' => 'Test loan',
    ]);

    $path = storage_path('app/legacy-migration-test-payments-loan.csv');

    AssociativeCsv::write($path, ['member_email', 'payment_date', 'amount'], [
        ['member_email' => 'borrower-member@fund.test', 'payment_date' => '2025-10-01', 'amount' => '500'],
    ]);

    $result = app(LegacyPaymentClassifierService::class)->classifyFile($path, now()->parse('2025-12-31'));

    expect($result['stats']['loan_repayment'])->toBe(1);

    @unlink($path);
});
