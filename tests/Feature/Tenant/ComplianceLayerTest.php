<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\MigrationCycleStub;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Services\FundAuditLogService;
use App\Services\MigrationCycleService;
use App\Services\MigrationOpeningBalanceService;
use App\Services\ReconciliationService;
use App\Support\BatchPostingGate;
use App\Support\ScheduledJobRegistry;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    MigrationCycleStub::query()->delete();
    ReconciliationException::query()->delete();
    FundAuditLog::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
});

test('migration service generates historical stubs', function () {
    $member = Member::create([
        'member_number' => 'MIG-001',
        'name' => 'Migrating Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2024-01-15'),
        'status' => 'active',
    ]);

    $count = app(MigrationCycleService::class)->generateHistoricalStubs(
        $member,
        Carbon::parse('2024-03-01'),
    );

    expect($count)->toBeGreaterThan(0)
        ->and($member->fresh()->migration_status)->toBe('migration_pending')
        ->and(MigrationCycleStub::query()->where('member_id', $member->id)->count())->toBe($count);
});

test('migration historical stubs use configured cycle start day', function () {
    Setting::set('contribution', 'cycle_start_day', '10');

    $member = Member::create([
        'member_number' => 'MIG-CYCLE-001',
        'name' => 'Cycle Day Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2024-01-15'),
        'status' => 'active',
    ]);

    app(MigrationCycleService::class)->generateHistoricalStubs(
        $member,
        Carbon::parse('2024-03-15'),
    );

    $dates = MigrationCycleStub::query()
        ->where('member_id', $member->id)
        ->orderBy('cycle_date')
        ->pluck('cycle_date')
        ->map(fn($date) => $date->format('Y-m-d'))
        ->all();

    expect($dates)->toBe(['2024-01-10', '2024-02-10', '2024-03-10']);
});

test('migration historical stubs exclude cycles after cutoff when start day is later in month', function () {
    Setting::set('contribution', 'cycle_start_day', '10');

    $member = Member::create([
        'member_number' => 'MIG-CYCLE-002',
        'name' => 'Cutoff Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2024-01-15'),
        'status' => 'active',
    ]);

    app(MigrationCycleService::class)->generateHistoricalStubs(
        $member,
        Carbon::parse('2024-03-05'),
    );

    $dates = MigrationCycleStub::query()
        ->where('member_id', $member->id)
        ->orderBy('cycle_date')
        ->pluck('cycle_date')
        ->map(fn($date) => $date->format('Y-m-d'))
        ->all();

    expect($dates)->toBe(['2024-01-10', '2024-02-10']);
});

test('migration delete stubs removes selected cycles and clears enrollment when none remain', function () {
    $member = Member::create([
        'member_number' => 'MIG-DEL-001',
        'name' => 'Delete Stubs Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2024-01-15'),
        'status' => 'active',
    ]);

    $service = app(MigrationCycleService::class);
    $service->generateHistoricalStubs($member, Carbon::parse('2024-03-01'));

    $stubs = MigrationCycleStub::query()->where('member_id', $member->id)->get();

    expect($stubs)->not->toBeEmpty()
        ->and($member->fresh()->migration_status)->toBe('migration_pending');

    $deleted = $service->deleteStubsForMember($member, $stubs);

    expect($deleted)->toBe($stubs->count())
        ->and(MigrationCycleStub::query()->where('member_id', $member->id)->count())->toBe(0)
        ->and($member->fresh()->migration_status)->toBeNull();
});

test('migration classify stubs applies classification to open cycles only', function () {
    $member = Member::create([
        'member_number' => 'MIG-BATCH-001',
        'name' => 'Batch Classify Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2024-01-15'),
        'status' => 'active',
    ]);

    $service = app(MigrationCycleService::class);
    $service->generateHistoricalStubs($member, Carbon::parse('2024-03-01'));

    $stubs = MigrationCycleStub::query()->where('member_id', $member->id)->get();

    $count = $service->classifyStubs($stubs, MigrationCycleStub::CLASS_WAIVED, notes: 'Batch waive');

    expect($count)->toBe($stubs->count())
        ->and(MigrationCycleStub::query()
            ->where('member_id', $member->id)
            ->where('classification', MigrationCycleStub::CLASS_WAIVED)
            ->count())->toBe($stubs->count());
});

test('migration reset removes all stubs and clears enrollment', function () {
    $member = Member::create([
        'member_number' => 'MIG-RESET-001',
        'name' => 'Reset Member',
        'monthly_contribution_amount' => 1000,
        'joined_at' => Carbon::parse('2024-01-15'),
        'status' => 'active',
        'migration_cutoff_date' => '2024-03-01',
    ]);

    $service = app(MigrationCycleService::class);
    $service->generateHistoricalStubs($member, Carbon::parse('2024-03-01'));

    $count = $service->resetMigrationForMember($member->fresh());

    expect($count)->toBeGreaterThan(0)
        ->and(MigrationCycleStub::query()->where('member_id', $member->id)->count())->toBe(0)
        ->and($member->fresh()->migration_status)->toBeNull()
        ->and($member->fresh()->migration_cutoff_date)->toBeNull();
});

test('fund audit log stores checksum', function () {
    $log = app(FundAuditLogService::class)->log('TEST_EVENT', 'test', payload: ['foo' => 'bar']);

    expect($log->checksum)->not->toBeEmpty()
        ->and(strlen($log->checksum))->toBe(64);
});

test('nightly reconciliation completes when master accounts balance', function () {
    $result = app(ReconciliationService::class)->runNightlyBatch();

    expect($result['halted'])->toBeFalse();
});

test('loan computeExemption supports zero and two grace cycles', function () {
    $disbursed = Carbon::parse('2026-05-10');

    $none = Loan::computeExemptionAndFirstRepayment($disbursed, 0);
    $two = Loan::computeExemptionAndFirstRepayment($disbursed, 2);

    expect($none['exempted_month'])->toBeNull()
        ->and($two['exempted_month'])->not->toBeNull()
        ->and($two['first_repayment_year'])->toBeGreaterThanOrEqual($none['first_repayment_year']);
});

test('scheduled job registry includes fund commands', function () {
    $keys = array_column(ScheduledJobRegistry::all(), 'key');

    expect($keys)->toContain('fund:nightly-reconciliation')
        ->and($keys)->toContain('loans:check-defaults');
});

test('batch posting gate halts when critical reconciliation is open', function () {
    ReconciliationException::create([
        'exception_code' => 'MASTER_IMBALANCE_UNRESOLVED',
        'domain' => 'master_account',
        'severity' => 'critical',
        'status' => 'open',
        'raised_at' => now(),
    ]);

    expect(app(BatchPostingGate::class)->isHalted())->toBeTrue();
});

test('loan status options include partially disbursed and repaid labels', function () {
    $options = Loan::statusOptions();

    expect($options)->toHaveKey('partially_disbursed')
        ->and($options['completed'])->toBe(__('Repaid'));
});

test('migration opening balance posts MIGRATION_OPENING entries', function () {
    $member = Member::create([
        'member_number' => 'OB-001',
        'name' => 'Opening Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);

    app(MigrationOpeningBalanceService::class)->postOpeningBalances($member, 500, 300);

    $member->refresh();

    expect($member->opening_balances_posted_at)->not->toBeNull()
        ->and((float) $member->opening_cash_balance)->toBe(500.0)
        ->and(Transaction::query()->where('description', 'like', 'MIGRATION_OPENING%')->count())->toBeGreaterThan(0);
});

test('transaction observer flags ineligible posting for migration pending member', function () {
    $member = Member::create([
        'member_number' => 'MIG-002',
        'name' => 'Pending Migration',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
        'migration_status' => 'migration_pending',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    $cash = $member->cashAccount;

    Transaction::create([
        'account_id' => $cash->id,
        'member_id' => $member->id,
        'type' => 'credit',
        'amount' => 100,
        'balance_after' => 100,
        'description' => 'Regular deposit',
        'transacted_at' => now(),
    ]);

    expect(
        ReconciliationException::query()
            ->where('exception_code', 'INELIGIBLE_ACCOUNT_POSTING')
            ->exists()
    )->toBeTrue();
});
