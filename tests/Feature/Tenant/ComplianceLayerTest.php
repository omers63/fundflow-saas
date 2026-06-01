<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationException;
use App\Services\FundAuditLogService;
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
    ReconciliationException::query()->delete();
    FundAuditLog::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
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
