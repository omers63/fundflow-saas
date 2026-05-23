<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\JobsPage;
use App\Filament\Tenant\Resources\FundAuditLogs\FundAuditLogResource;
use App\Filament\Tenant\Resources\LoanEligibilityOverrides\LoanEligibilityOverrideResource;
use App\Filament\Tenant\Resources\ReconciliationExceptions\ReconciliationExceptionResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankStatement;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\MigrationCycleStub;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\BankClearingMatchService;
use App\Services\ContributionCollectionCycleService;
use App\Services\MemberInvariantService;
use App\Services\MigrationCycleService;
use App\Services\MigrationOpeningBalanceService;
use App\Services\ReconciliationCorrectionService;
use App\Services\ReconciliationResolutionService;
use App\Services\ReconciliationService;
use App\Support\ContributionCollectionStatus;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    MigrationCycleStub::query()->delete();
    ReconciliationException::query()->delete();
    Contribution::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
});

test('partial clearance grants active status while migration stubs may remain escalated', function () {
    $member = Member::create([
        'member_number' => 'PC-001',
        'name' => 'Partial Clear',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
        'migration_status' => 'migration_pending',
    ]);

    MigrationCycleStub::create([
        'member_id' => $member->id,
        'cycle_date' => now()->subMonths(2)->startOfMonth(),
        'amount_due' => 500,
        'status' => 'escalated',
        'classification' => MigrationCycleStub::CLASS_ESCALATED,
        'late_fee_exempt' => true,
    ]);

    app(MigrationCycleService::class)->grantPartialClearance($member, 'Long history under review');

    $member->refresh();

    expect($member->migration_status)->toBe('active')
        ->and($member->partial_clearance_granted_at)->not->toBeNull();
});

test('reconciliation resolution service escalates open exception', function () {
    $exception = ReconciliationException::create([
        'exception_code' => 'TEST_CODE',
        'domain' => 'contribution',
        'severity' => 'medium',
        'status' => ReconciliationException::STATUS_OPEN,
        'raised_at' => now(),
        'sla_deadline' => now()->addDay(),
    ]);

    $updated = app(ReconciliationResolutionService::class)->escalate($exception, 'Needs supervisor review');

    expect($updated->status)->toBe(ReconciliationException::STATUS_ESCALATED)
        ->and($updated->resolution_action)->toBe(ReconciliationResolutionService::ACTION_ESCALATED);
});

test('contribution collection uses settling status during partial payment', function () {
    $member = Member::create([
        'member_number' => 'SET-001',
        'name' => 'Settling Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    app(AccountingService::class)->credit(
        $member->cashAccount,
        300,
        'Test deposit',
        null,
    );

    $period = Contribution::periodDate((int) now()->month, (int) now()->year);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => $period,
        'amount' => 1000,
        'amount_due' => 1000,
        'amount_collected' => 0,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
    ]);

    app(ContributionCollectionCycleService::class)->attemptCollection($contribution);

    $contribution->refresh();

    expect($contribution->collection_status)->toBeIn([
        ContributionCollectionStatus::PARTIALLY_PENDING,
        ContributionCollectionStatus::SETTLING,
    ]);
});

test('member invariant uses spec formula components and balances after opening post', function () {
    $member = Member::create([
        'member_number' => 'INV-001',
        'name' => 'Invariant Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
        'migration_status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    app(MigrationOpeningBalanceService::class)->postOpeningBalances($member, 200, 400);

    $result = app(MemberInvariantService::class)->check($member->fresh());

    expect($result['balanced'])->toBeTrue()
        ->and($result['components']['opening_fund'])->toBe(400.0)
        ->and($result['components']['opening_cash'])->toBe(200.0);
});

test('reconciliation raises ambiguous bank match from scan', function () {
    $member = Member::create([
        'member_number' => 'BANK-001',
        'name' => 'Bank Match Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $postingA = FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => now(),
        'amount' => 1500,
        'status' => 'accepted',
        'reviewed_at' => now(),
    ]);

    $postingB = FundPosting::create([
        'member_id' => $member->id,
        'posting_date' => now()->subDay(),
        'amount' => 1500,
        'status' => 'accepted',
        'reviewed_at' => now()->subDay(),
    ]);

    $statement = BankStatement::create([
        'filename' => 'recon-ambiguous.csv',
        'status' => 'completed',
        'total_rows' => 3,
        'imported_rows' => 3,
        'duplicate_rows' => 0,
    ]);

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'amount' => 1500,
        'description' => 'Imported line',
        'reference' => 'IMP-1',
        'transaction_date' => now(),
        'status' => 'imported',
        'is_cleared' => true,
        'hash' => md5('imported-ambiguous'),
    ]);

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'amount' => 1500,
        'description' => 'Pending A',
        'reference' => 'PEN-A',
        'transaction_date' => now(),
        'status' => 'imported',
        'is_cleared' => false,
        'fund_posting_id' => $postingA->id,
        'hash' => md5('pending-a'),
    ]);

    BankTransaction::create([
        'bank_statement_id' => $statement->id,
        'amount' => 1500,
        'description' => 'Pending B',
        'reference' => 'PEN-B',
        'transaction_date' => now()->subDay(),
        'status' => 'imported',
        'is_cleared' => false,
        'fund_posting_id' => $postingB->id,
        'hash' => md5('pending-b'),
    ]);

    $scan = app(BankClearingMatchService::class)->scanMatchExceptions();

    expect($scan['ambiguous'])->not->toBeEmpty();

    app(ReconciliationService::class)->runNightlyBatch();

    expect(ReconciliationException::query()
        ->where('exception_code', 'RECON_AMBIGUOUS_MATCH')
        ->open()
        ->exists())->toBeTrue();
});

test('correction service reverses a linked transaction', function () {
    $member = Member::create([
        'member_number' => 'REV-001',
        'name' => 'Reversal Member',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    app(AccountingService::class)->credit($member->cashAccount, 50, 'Seed cash');

    $original = Transaction::query()->where('type', 'credit')->first();

    $exception = ReconciliationException::create([
        'exception_code' => 'TEST_REVERSAL',
        'domain' => 'contribution',
        'severity' => 'medium',
        'status' => ReconciliationException::STATUS_OPEN,
        'raised_at' => now(),
        'sla_deadline' => now()->addDay(),
        'affected_entities' => ['transaction_id' => $original->id],
    ]);

    $result = app(ReconciliationCorrectionService::class)->reverseLinkedTransaction(
        $exception,
        $original->id,
        'Test reversal',
    );

    expect($result['reversal_count'])->toBe(1)
        ->and(Transaction::query()->where('reference_type', Transaction::class)->count())->toBe(1);
});

test('resolution service posts member cash correction', function () {
    $member = Member::create([
        'member_number' => 'COR-001',
        'name' => 'Correction Member',
        'monthly_contribution_amount' => 100,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    $exception = ReconciliationException::create([
        'exception_code' => 'TEST_CORRECTION',
        'domain' => 'contribution',
        'severity' => 'medium',
        'status' => ReconciliationException::STATUS_OPEN,
        'raised_at' => now(),
        'sla_deadline' => now()->addDay(),
        'affected_entities' => ['member_id' => $member->id],
    ]);

    app(ReconciliationResolutionService::class)->postMemberCashCorrection(
        $exception,
        $member->id,
        'credit',
        25,
        'Manual recon correction',
    );

    $member->refresh();

    expect((float) $member->cashAccount->balance)->toBe(25.0)
        ->and($exception->fresh()->status)->toBe(ReconciliationException::STATUS_RESOLVED);
});

test('loan full repayment threshold matches master portion plus settlement slice', function () {
    $loan = new Loan([
        'master_portion' => 8000,
        'amount_approved' => 10000,
        'settlement_threshold' => 0.16,
    ]);

    expect($loan->fullRepaymentThreshold())->toBe(9600.0);
});

test('nightly reconciliation raises fund tier over committed', function () {
    $loanTier = LoanTier::query()->first() ?? LoanTier::create([
        'label' => 'Test tier',
        'min_amount' => 1000,
        'max_amount' => 50000,
        'min_monthly_installment' => 500,
        'is_active' => true,
    ]);

    $fundTier = FundTier::create([
        'tier_number' => 99,
        'label' => 'Test pool',
        'loan_tier_id' => $loanTier->id,
        'percentage' => 10,
        'is_active' => true,
    ]);

    $member = Member::create([
        'member_number' => 'FT-001',
        'name' => 'Tier Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
        'migration_status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    app(MigrationOpeningBalanceService::class)->postOpeningBalances($member, 0, 40000);

    Loan::create([
        'member_id' => $member->id,
        'fund_tier_id' => $fundTier->id,
        'loan_tier_id' => $loanTier->id,
        'amount' => 5000,
        'amount_requested' => 5000,
        'status' => 'approved',
        'amount_approved' => 5000,
        'amount_disbursed' => 0,
        'master_portion' => 5000,
        'member_portion' => 0,
        'settlement_threshold' => 0.16,
        'interest_rate' => 0,
        'term_months' => 12,
        'monthly_repayment' => 500,
        'total_repaid' => 0,
        'applied_at' => now(),
    ]);

    app(ReconciliationService::class)->runNightlyBatch();

    expect(ReconciliationException::query()
        ->where('exception_code', 'FUND_TIER_OVER_COMMITTED')
        ->open()
        ->exists())->toBeTrue();
});

test('migration reconciliation detects opening fund sum drift', function () {
    $member = Member::create([
        'member_number' => 'MOS-001',
        'name' => 'Opening Sum Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
        'migration_status' => 'active',
        'migration_cutoff_date' => now()->subMonth(),
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    app(MigrationOpeningBalanceService::class)->postOpeningBalances($member, 0, 1000);

    $masterFund = Account::masterFund();
    $memberFund = $member->fundAccount;
    app(AccountingService::class)->credit($masterFund, 500, 'MIGRATION_OPENING — fund — extra', null, null, $member->id);
    app(AccountingService::class)->credit($memberFund, 500, 'MIGRATION_OPENING — fund — extra', null, null, $member->id);

    $recon = app(ReconciliationService::class);
    $method = new ReflectionMethod($recon, 'reconcileMigration');
    $method->setAccessible(true);
    $method->invoke($recon);

    expect(ReconciliationException::query()
        ->where('exception_code', 'MIGRATION_OPENING_SUM_DRIFT')
        ->open()
        ->exists())->toBeTrue();
});

test('jobs page registers in tenant panel navigation', function () {
    $admin = User::create([
        'name' => 'Jobs Admin',
        'email' => 'jobs-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Filament::setCurrentPanel('tenant');
    $this->actingAs($admin, 'tenant');

    expect(JobsPage::canAccess())->toBeTrue()
        ->and(JobsPage::shouldRegisterNavigation())->toBeTrue()
        ->and(JobsPage::getUrl())->toContain('/admin/jobs');
});

test('loan eligibility overrides resource is in system navigation', function () {
    expect(LoanEligibilityOverrideResource::shouldRegisterNavigation())->toBeTrue();
});

test('reconciliation resource registers in tenant panel navigation', function () {
    $admin = User::create([
        'name' => 'Recon Admin',
        'email' => 'recon-admin@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);

    Filament::setCurrentPanel('tenant');
    $this->actingAs($admin, 'tenant');

    expect(ReconciliationExceptionResource::canAccess())->toBeTrue()
        ->and(ReconciliationExceptionResource::shouldRegisterNavigation())->toBeTrue()
        ->and(ReconciliationExceptionResource::getUrl('index'))->toContain('reconciliation-exceptions');
});

test('fund audit log resource is read-only in navigation', function () {
    expect(FundAuditLogResource::canCreate())->toBeFalse()
        ->and(FundAuditLogResource::canEdit(new FundAuditLog))->toBeFalse();
});

test('custom journal correction posts balanced multi-leg entry', function () {
    $member = Member::create([
        'member_number' => 'CJ-001',
        'name' => 'Journal Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    $member->load('cashAccount', 'fundAccount');

    $masterFund = Account::masterFund();
    $masterFund?->update(['balance' => 10000]);
    $member->cashAccount?->update(['balance' => 0]);
    $member->fundAccount?->update(['balance' => 0]);

    $exception = ReconciliationException::create([
        'exception_code' => 'TEST_JOURNAL',
        'domain' => 'contribution',
        'severity' => 'medium',
        'status' => ReconciliationException::STATUS_OPEN,
        'amount_delta' => 150,
        'affected_entities' => ['member_id' => $member->id],
        'raised_at' => now(),
    ]);

    app(ReconciliationCorrectionService::class)->postCustomJournal(
        $exception,
        [
            ['account_id' => $member->cashAccount->id, 'type' => 'credit', 'amount' => 150],
            ['account_id' => $masterFund->id, 'type' => 'debit', 'amount' => 150],
        ],
        'Supervisor adjustment',
    );

    $exception->refresh();

    expect($exception->resolution_action)->toBe(ReconciliationCorrectionService::ACTION_MANUAL_CORRECTION)
        ->and((float) $member->cashAccount->fresh()->balance)->toBe(150.0)
        ->and(Transaction::query()
            ->where('reference_type', ReconciliationException::class)
            ->where('reference_id', $exception->id)
            ->count())->toBe(2);
});

test('late fee reconciliation detects fee posted to wrong account', function () {
    $member = Member::create([
        'member_number' => 'FEE-001',
        'name' => 'Fee Wrong Account',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    $memberFund = $member->fundAccount;
    app(AccountingService::class)->credit($memberFund, 25, 'Contribution late fee — wrong account', null, null, $member->id);

    $recon = app(ReconciliationService::class);
    $method = new ReflectionMethod($recon, 'reconcileLateFees');
    $method->setAccessible(true);
    $method->invoke($recon);

    expect(ReconciliationException::query()
        ->where('exception_code', 'FEE_POSTED_WRONG_ACCOUNT')
        ->open()
        ->exists())->toBeTrue();
});
