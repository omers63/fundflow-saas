<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\JobsPage;
use App\Filament\Tenant\Pages\ReconciliationOverviewPage;
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
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\BankClearingMatchService;
use App\Services\ContributionCollectionCycleService;
use App\Services\ContributionService;
use App\Services\MasterFeeDisbursementService;
use App\Services\MemberInvariantService;
use App\Services\MemberOpeningBalanceService;
use App\Services\ReconciliationCorrectionService;
use App\Services\ReconciliationResolutionService;
use App\Services\ReconciliationService;
use App\Support\ContributionCollectionStatus;
use App\Support\ContributionPolicySettings;
use Filament\Facades\Filament;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    app()->setLocale('en');

    Account::query()->delete();
    Member::query()->delete();
    ReconciliationException::query()->delete();
    Contribution::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
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
    AccountingService::withoutMemberCashCollection(fn() => app(AccountingService::class)->credit(
        $member->cashAccount,
        300,
        'Test deposit',
        null,
    ));

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
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    app(MemberOpeningBalanceService::class)->postOpeningBalances(
        $member,
        200,
        400,
        now()->subMonth(),
    );

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
    AccountingService::withoutMemberCashCollection(fn() => app(AccountingService::class)->credit($member->cashAccount, 50, 'Seed cash'));

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
        ->and(Transaction::query()->where('reference_type', Transaction::class)->count())->toBe(2);
});

test('resolution service posts member cash correction', function () {
    $member = Member::create([
        'member_number' => 'COR-001',
        'name' => 'Correction Member',
        'monthly_contribution_amount' => 0,
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

test('loan schedule last installment absorbs repayment remainder below min emi', function () {
    expect(Loan::scheduleInstallmentAmount(10, 10, 1000, 9600))->toBe(600.0)
        ->and(Loan::scheduleInstallmentAmount(9, 10, 1000, 9600))->toBe(1000.0)
        ->and(Loan::scheduleInstallmentAmount(1, 1, 1000, 750))->toBe(750.0)
        ->and(Loan::scheduleInstallmentAmount(5, 5, 2000, 10000))->toBe(2000.0);

    $loan = new Loan([
        'master_portion' => 8000,
        'amount_approved' => 10000,
        'settlement_threshold' => 0.16,
        'installments_count' => 10,
        'monthly_repayment' => 1000,
    ]);

    expect($loan->scheduleInstallmentAmountFor(10))->toBe(600.0)
        ->and($loan->scheduleInstallmentAmountFor(3))->toBe(1000.0);
});

test('ensure schedule installment amount updates legacy last installment to closing remainder', function () {
    $loan = Loan::create([
        'member_id' => Member::create([
            'member_number' => 'LOAN-LAST-EMI',
            'name' => 'Last EMI Member',
            'monthly_contribution_amount' => 500,
            'joined_at' => now()->subYear(),
            'status' => 'active',
        ])->id,
        'amount' => 10000,
        'amount_requested' => 10000,
        'amount_approved' => 10000,
        'master_portion' => 8000,
        'member_portion' => 2000,
        'settlement_threshold' => 0.16,
        'installments_count' => 10,
        'term_months' => 10,
        'monthly_repayment' => 1000,
        'status' => 'active',
    ]);

    $last = LoanInstallment::create([
        'loan_id' => $loan->id,
        'installment_number' => 10,
        'amount' => 1000,
        'due_date' => now()->addMonths(10)->toDateString(),
        'status' => 'pending',
    ]);

    $loan->ensureScheduleInstallmentAmount($last);

    expect((float) $last->fresh()->amount)->toBe(600.0);
});

test('nightly reconciliation does not raise fund tier over committed', function () {
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
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    app(MemberOpeningBalanceService::class)->postOpeningBalances($member, 0, 40000, now()->subMonth());

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
        ->exists())->toBeFalse();
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
        ->and(JobsPage::shouldRegisterNavigation())->toBeFalse()
        ->and(JobsPage::getUrl())->toContain('/admin/jobs');
});

test('loan eligibility overrides resource is in system navigation', function () {
    expect(LoanEligibilityOverrideResource::shouldRegisterNavigation())->toBeFalse();
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
        ->and(ReconciliationExceptionResource::shouldRegisterNavigation())->toBeFalse()
        ->and(ReconciliationOverviewPage::getUrl(['sideTab' => 'exceptions']))->toContain('reconciliation');
});

test('reconciliation exception records can be deleted individually or in bulk', function () {
    $first = ReconciliationException::create([
        'exception_code' => 'DELETE_ONE',
        'domain' => 'master_account',
        'severity' => 'low',
        'status' => ReconciliationException::STATUS_RESOLVED,
        'raised_at' => now(),
    ]);

    $second = ReconciliationException::create([
        'exception_code' => 'DELETE_TWO',
        'domain' => 'master_account',
        'severity' => 'low',
        'status' => ReconciliationException::STATUS_OPEN,
        'raised_at' => now(),
    ]);

    $first->delete();

    expect(ReconciliationException::query()->whereKey($first->id)->exists())->toBeFalse()
        ->and(ReconciliationException::query()->whereKey($second->id)->exists())->toBeTrue();

    ReconciliationException::query()->whereKey($second->id)->delete();

    expect(ReconciliationException::query()->count())->toBe(0);
});

test('fund audit log resource is read-only in navigation', function () {
    expect(FundAuditLogResource::canCreate())->toBeFalse()
        ->and(FundAuditLogResource::canEdit(new FundAuditLog))->toBeFalse();
});

test('custom journal correction posts balanced multi-leg entry', function () {
    $member = Member::create([
        'member_number' => 'CJ-001',
        'name' => 'Journal Member',
        'monthly_contribution_amount' => 0,
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
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

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

test('late fee reconciliation accepts mirrored cash and master fees legs', function () {
    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $member = Member::create([
        'member_number' => 'FEE-002',
        'name' => 'Fee Correct Account',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 100]);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => now()->startOfMonth(),
        'amount' => 500,
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    AccountingService::withoutMemberCashCollection(function () use ($accounting, $contribution): void {
        $accounting->postContributionLateFee($contribution, 25);
    });

    ReconciliationException::query()->where('exception_code', 'FEE_POSTED_WRONG_ACCOUNT')->delete();

    $recon = app(ReconciliationService::class);
    $method = new ReflectionMethod($recon, 'reconcileLateFees');
    $method->setAccessible(true);
    $method->invoke($recon);

    expect(ReconciliationException::query()
        ->where('exception_code', 'FEE_POSTED_WRONG_ACCOUNT')
        ->open()
        ->exists())->toBeFalse();
});

test('replacement late fee reconciliation ignores master cash mirror debits', function () {
    Setting::set(ContributionPolicySettings::GROUP_COLLECTION, 'late_fee_model', 'replacement');

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 100, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $member = Member::create([
        'member_number' => 'FEE-003',
        'name' => 'Replacement Mirror OK',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 100]);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => now()->startOfMonth(),
        'amount' => 500,
        'status' => 'posted',
        'posted_at' => now(),
        'late_fee_amount' => 25,
        'late_fee_tier' => 1,
    ]);

    AccountingService::withoutMemberCashCollection(function () use ($accounting, $contribution): void {
        $accounting->postContributionLateFee($contribution, 25);
    });

    ReconciliationException::query()
        ->where('exception_code', 'REPLACEMENT_PRIOR_TIER_NOT_REVERSED')
        ->delete();

    $recon = app(ReconciliationService::class);
    $method = new ReflectionMethod($recon, 'reconcileLateFees');
    $method->setAccessible(true);
    $method->invoke($recon);

    expect($accounting->contributionLateFeeMemberCashDebitCount($contribution))->toBe(1)
        ->and(ReconciliationException::query()
            ->where('exception_code', 'REPLACEMENT_PRIOR_TIER_NOT_REVERSED')
            ->open()
            ->exists())->toBeFalse();
});

test('replacement late fee reconciliation flags duplicate member cash debits', function () {
    Setting::set(ContributionPolicySettings::GROUP_COLLECTION, 'late_fee_model', 'replacement');

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 200, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    $member = Member::create([
        'member_number' => 'FEE-004',
        'name' => 'Replacement Duplicate',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 200]);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => now()->startOfMonth(),
        'amount' => 500,
        'status' => 'posted',
        'posted_at' => now(),
        'late_fee_amount' => 50,
        'late_fee_tier' => 2,
    ]);

    AccountingService::withoutMemberCashCollection(function () use ($accounting, $contribution): void {
        $accounting->postContributionLateFee($contribution, 25);
        $accounting->postContributionLateFee($contribution, 25);
    });

    ReconciliationException::query()
        ->where('exception_code', 'REPLACEMENT_PRIOR_TIER_NOT_REVERSED')
        ->delete();

    $recon = app(ReconciliationService::class);
    $method = new ReflectionMethod($recon, 'reconcileLateFees');
    $method->setAccessible(true);
    $method->invoke($recon);

    expect($accounting->contributionLateFeeMemberCashDebitCount($contribution))->toBe(2)
        ->and(ReconciliationException::query()
            ->where('exception_code', 'REPLACEMENT_PRIOR_TIER_NOT_REVERSED')
            ->open()
            ->exists())->toBeTrue();
});

test('late fee reconciliation ignores fee disbursement debits when checking fee income drift', function () {
    $masterFees = Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    app(AccountingService::class)->credit($masterFees, 500, 'Collected fees', null, now());

    app(MasterFeeDisbursementService::class)->disburse($masterFees->fresh(), 200, 'Fee payout');

    ReconciliationException::query()->where('exception_code', 'FEE_INCOME_DRIFT')->delete();

    $recon = app(ReconciliationService::class);
    $method = new ReflectionMethod($recon, 'reconcileLateFees');
    $method->setAccessible(true);
    $method->invoke($recon);

    expect(ReconciliationException::query()
        ->where('exception_code', 'FEE_INCOME_DRIFT')
        ->open()
        ->exists())->toBeFalse();
});

test('late fee reconciliation still detects fee income drift when balance diverges from net ledger', function () {
    $masterFees = Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);

    app(AccountingService::class)->credit($masterFees, 500, 'Collected fees', null, now());
    $masterFees->update(['balance' => 100]);

    ReconciliationException::query()->where('exception_code', 'FEE_INCOME_DRIFT')->delete();

    $recon = app(ReconciliationService::class);
    $method = new ReflectionMethod($recon, 'reconcileLateFees');
    $method->setAccessible(true);
    $method->invoke($recon);

    expect(ReconciliationException::query()
        ->where('exception_code', 'FEE_INCOME_DRIFT')
        ->open()
        ->exists())->toBeTrue();
});

test('realtime pool drift is not raised while master cash mirror is in progress', function () {
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
    Account::masterCash()->update(['balance' => 500]);

    $member = Member::create([
        'member_number' => 'POOL-001',
        'name' => 'Pool Mirror Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 500]);

    ReconciliationException::query()
        ->whereIn('exception_code', ['MASTER_CASH_POOL_DRIFT', 'MEMBER_CASH_DRIFT'])
        ->delete();

    AccountingService::withoutMemberCashCollection(function () use ($accounting, $member): void {
        $accounting->debitMemberCashWithMasterMirror(
            $member->cashAccount,
            100,
            'Pool mirror test',
            '(test)',
        );
    });

    expect(ReconciliationException::query()
        ->whereIn('exception_code', ['MASTER_CASH_POOL_DRIFT', 'MEMBER_CASH_DRIFT'])
        ->open()
        ->exists())->toBeFalse()
        ->and((float) Account::masterCash()->fresh()->balance)->toBe(400.0)
        ->and((float) $member->cashAccount->fresh()->balance)->toBe(400.0);
});

test('member cash invariant stays balanced after mirrored contribution late fee', function () {
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
    Account::masterCash()->update(['balance' => 500]);

    $member = Member::create([
        'member_number' => 'INV-LATE',
        'name' => 'Late Fee Invariant',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
        'opening_cash_balance' => 500,
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 500]);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => now()->startOfMonth(),
        'amount' => 500,
        'status' => 'posted',
        'posted_at' => now(),
    ]);

    AccountingService::withoutMemberCashCollection(function () use ($accounting, $contribution): void {
        $accounting->postContributionLateFee($contribution, 30);
    });

    $result = app(MemberInvariantService::class)->check($member->fresh());

    expect($result['balanced'])->toBeTrue()
        ->and($result['components']['late_fees_net'])->toBe(30.0);
});

test('late fees settled column sums member cash debits only not master cash mirror', function () {
    if (Account::query()->where('type', 'fees')->where('is_master', true)->doesntExist()) {
        Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
    }
    Account::masterCash()->update(['balance' => 500]);

    $member = Member::create([
        'member_number' => 'LATE-SUM',
        'name' => 'Late Fee Sum',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 500]);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => now()->startOfMonth(),
        'amount' => 500,
        'status' => 'posted',
        'posted_at' => now(),
        'late_fee_amount' => 100,
    ]);

    AccountingService::withoutMemberCashCollection(function () use ($accounting, $contribution): void {
        $accounting->postContributionLateFee($contribution, 100);
    });

    expect($accounting->contributionLateFeeCollectedAmount($contribution))->toBe(100.0);

    $loaded = Contribution::query()
        ->withLateFeeCollectedAmountSum()
        ->findOrFail($contribution->id);

    expect((float) $loaded->late_fee_collected_amount)->toBe(100.0);

    $mirrorLegCount = Transaction::query()
        ->where('reference_type', Contribution::class)
        ->where('reference_id', $contribution->id)
        ->where('type', 'debit')
        ->where('description', 'like', __('Contribution late fee —').'%')
        ->count();

    expect($mirrorLegCount)->toBe(2);
});

test('member fund invariant stays balanced after mirrored contribution post', function () {
    Account::masterCash()->update(['balance' => 5000]);
    Account::masterFund()->update(['balance' => 0]);

    $member = Member::create([
        'member_number' => 'INV-FUND',
        'name' => 'Fund Invariant',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
        'opening_cash_balance' => 5000,
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($member);
    $member->cashAccount->update(['balance' => 5000]);

    $contribution = Contribution::create([
        'member_id' => $member->id,
        'period' => now()->startOfMonth(),
        'amount' => 500,
        'status' => 'pending',
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
    ]);

    AccountingService::withoutMemberCashCollection(function () use ($accounting, $contribution): void {
        $accounting->postContributionPrincipal($contribution, 500);
    });

    $result = app(MemberInvariantService::class)->check($member->fresh());

    expect($result['balanced'])->toBeTrue()
        ->and($result['components']['contributions_collected'])->toBe(500.0)
        ->and((float) Account::masterFund()->fresh()->balance)->toBe(500.0);
});

test('pending past window close is not raised for contribution-exempt members', function () {
    $member = Member::create([
        'member_number' => 'EXEMPT-PEND',
        'name' => 'Exempt Pending',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    $pastPeriod = now()->subMonths(3)->startOfMonth();

    Contribution::create([
        'member_id' => $member->id,
        'period' => $pastPeriod,
        'amount' => 500,
        'status' => 'pending',
        'collection_status' => ContributionCollectionStatus::PENDING,
    ]);

    $loan = Loan::factory()->for($member)->create([
        'status' => 'active',
        'member_portion' => 10_000,
        'master_portion' => 0,
        'amount_disbursed' => 10_000,
        'disbursed_at' => $pastPeriod->copy()->subMonth(),
        'first_repayment_month' => (int) $pastPeriod->month,
        'first_repayment_year' => (int) $pastPeriod->year,
    ]);

    $loan->installments()->create([
        'installment_number' => 1,
        'due_date' => now()->subMonth(),
        'amount' => 500,
        'status' => 'pending',
    ]);

    ReconciliationException::query()->where('exception_code', 'PENDING_PAST_WINDOW_CLOSE')->delete();

    $recon = app(ReconciliationService::class);
    $method = new ReflectionMethod($recon, 'reconcileContributions');
    $method->setAccessible(true);
    $method->invoke($recon);

    expect(ReconciliationException::query()
        ->where('exception_code', 'PENDING_PAST_WINDOW_CLOSE')
        ->where('affected_entities->member_id', $member->id)
        ->open()
        ->exists())->toBeFalse();
});

test('reconciliation does not flag legacy imported contributions collected during loan exempt cycles', function () {
    $member = Member::create([
        'member_number' => 'LEG-EXEMPT',
        'name' => 'Legacy Exempt Cycle',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
    ]);

    $loanPeriod = now()->subMonths(6)->startOfMonth();

    $loan = Loan::factory()->for($member)->create([
        'status' => 'completed',
        'amount_disbursed' => 10_000,
        'member_portion' => 10_000,
        'master_portion' => 0,
        'disbursed_at' => $loanPeriod->copy()->subMonth(),
        'completed_at' => $loanPeriod->copy()->addMonths(3),
    ]);

    ContributionService::withoutLiveCollectionGuards(fn() => Contribution::create([
        'member_id' => $member->id,
        'period' => $loanPeriod,
        'amount' => 500,
        'amount_collected' => 500,
        'status' => 'posted',
        'collection_status' => ContributionCollectionStatus::COLLECTED,
        'posted_at' => $loanPeriod,
        'payment_method' => Contribution::PAYMENT_METHOD_CASH_ACCOUNT,
        'notes' => 'Legacy migration contribution [legacy-import:LEG-EXEMPT||'.$loanPeriod->format('Y-m-d').'|500|contribution|'.$loanPeriod->format('Y-m').']',
    ]));

    expect($member->isExemptFromContributions(
        (int) $loanPeriod->month,
        (int) $loanPeriod->year,
    ))->toBeTrue();

    ReconciliationException::query()->where('exception_code', 'CONTRIBUTION_EXEMPT_COLLECTED')->delete();

    $recon = app(ReconciliationService::class);
    $method = new ReflectionMethod($recon, 'reconcileContributions');
    $method->setAccessible(true);
    $method->invoke($recon);

    expect(ReconciliationException::query()
        ->where('exception_code', 'CONTRIBUTION_EXEMPT_COLLECTED')
        ->where('affected_entities->member_id', $member->id)
        ->open()
        ->exists())->toBeFalse();
});

test('reconciliation still flags live contributions collected during loan exempt cycles', function () {
    $member = Member::create([
        'member_number' => 'LIVE-EXEMPT',
        'name' => 'Live Exempt Cycle',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYears(2),
        'status' => 'active',
    ]);

    $loanPeriod = now()->subMonths(4)->startOfMonth();

    Loan::factory()->for($member)->create([
        'status' => 'active',
        'amount_disbursed' => 10_000,
        'member_portion' => 10_000,
        'master_portion' => 0,
        'disbursed_at' => $loanPeriod->copy()->subMonth(),
    ]);

    $contribution = ContributionService::withoutLiveCollectionGuards(fn() => Contribution::create([
        'member_id' => $member->id,
        'period' => $loanPeriod,
        'amount' => 500,
        'amount_collected' => 500,
        'status' => 'posted',
        'collection_status' => ContributionCollectionStatus::COLLECTED,
        'posted_at' => $loanPeriod,
        'payment_method' => Contribution::PAYMENT_METHOD_ADMIN,
        'notes' => 'Manual correction',
    ]));

    expect($member->isExemptFromContributions(
        (int) $loanPeriod->month,
        (int) $loanPeriod->year,
    ))->toBeTrue();

    ReconciliationException::query()->where('exception_code', 'CONTRIBUTION_EXEMPT_COLLECTED')->delete();

    $recon = app(ReconciliationService::class);
    $method = new ReflectionMethod($recon, 'reconcileContributions');
    $method->setAccessible(true);
    $method->invoke($recon);

    expect(ReconciliationException::query()
        ->where('exception_code', 'CONTRIBUTION_EXEMPT_COLLECTED')
        ->where('affected_entities->contribution_id', $contribution->id)
        ->open()
        ->exists())->toBeTrue();
});

test('loan disbursement reconciliation accepts mirrored member cash credits without cash payout suffix', function () {
    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 200_000, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 200_000, 'is_master' => true]);

    $member = Member::create([
        'member_number' => 'RECON-DISB-1',
        'name' => 'Disbursement Recon Member',
        'monthly_contribution_amount' => 5000,
        'joined_at' => now()->subMonths(18),
        'status' => 'active',
    ]);

    $accounting = app(AccountingService::class);
    $accounting->createMemberAccounts($member);

    $loan = Loan::factory()->for($member)->create([
        'status' => 'active',
        'amount_approved' => 80_000,
        'amount_disbursed' => 80_000,
        'member_portion' => 40_000,
        'master_portion' => 40_000,
    ]);

    foreach ([1 => 40_000, 2 => 40_000] as $sequence => $amount) {
        $label = __('Loan #:id disbursement (#:seq) – :name', [
            'id' => $loan->id,
            'seq' => $sequence,
            'name' => $member->name,
        ]);

        $accounting->debitMemberFundWithMasterMirror(
            $member->fundAccount,
            $amount,
            $label,
            __('(member fund share)'),
            $loan,
            memberId: $member->id,
        );

        $accounting->creditMemberCashWithMasterMirror(
            $member->cashAccount,
            $amount,
            $label,
            __('(cash payout mirror)'),
            $loan,
            memberId: $member->id,
        );
    }

    ReconciliationException::query()->where('exception_code', 'DISBURSEMENT_MEMBER_CASH_MISSING')->delete();

    $recon = app(ReconciliationService::class);
    $method = new ReflectionMethod($recon, 'reconcileLoansAndEmi');
    $method->setAccessible(true);
    $method->invoke($recon);

    expect(ReconciliationException::query()
        ->where('exception_code', 'DISBURSEMENT_MEMBER_CASH_MISSING')
        ->where('affected_entities->loan_id', $loan->id)
        ->open()
        ->exists())->toBeFalse();
});
