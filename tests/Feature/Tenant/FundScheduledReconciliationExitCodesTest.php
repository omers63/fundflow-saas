<?php

declare(strict_types=1);

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Services\AccountingService;
use App\Services\ReconciliationDigestService;
use App\Services\ReconciliationReportService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();

    Account::query()->delete();
    Member::query()->delete();
    ReconciliationSnapshot::query()->delete();

    Account::create(['type' => 'cash', 'name' => 'Master Cash', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fund', 'name' => 'Master Fund', 'balance' => 0, 'is_master' => true]);
    Account::create(['type' => 'fees', 'name' => 'Master Fees', 'balance' => 0, 'is_master' => true]);
});

/**
 * @return array<string, mixed>
 */
function failingReconcileReport(): array
{
    return [
        'meta' => ['as_of' => now()->toIso8601String()],
        'checks' => ['ledger_balances' => ['mismatch_count' => 1]],
        'pipeline' => ['bank_unposted_count' => 0],
        'control_layer' => ['open_exception_count' => 1],
        'coverage_matrix' => [],
        'verdict' => [
            'pass' => false,
            'critical_issues' => 1,
            'warnings' => 0,
        ],
    ];
}

test('assert master invariants exits success when imbalanced so the scheduler does not log ERROR', function () {
    $member = Member::create([
        'member_number' => 'SCHED-INV-1',
        'name' => 'Sched Invariant Member',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    app(AccountingService::class)->credit($member->fundAccount, 1800, 'Seed fund without master mirror');

    expect(Artisan::call('fund:assert-master-invariants', ['--force' => true, '--tenants' => ['testing']]))->toBe(0);
});

test('assert master invariants --strict exits failure when imbalanced', function () {
    $member = Member::create([
        'member_number' => 'SCHED-INV-2',
        'name' => 'Sched Invariant Strict',
        'monthly_contribution_amount' => 500,
        'joined_at' => now()->subYear(),
        'status' => 'active',
    ]);

    app(AccountingService::class)->createMemberAccounts($member);
    app(AccountingService::class)->credit($member->fundAccount, 1800, 'Seed fund without master mirror');

    expect(Artisan::call('fund:assert-master-invariants', ['--force' => true, '--strict' => true, '--tenants' => ['testing']]))->toBe(1);
});

test('fund reconcile exits success when verdict fails so the scheduler does not log ERROR', function () {
    $report = failingReconcileReport();
    $snapshot = new ReconciliationSnapshot(['id' => 99]);
    $snapshot->id = 99;

    $this->mock(ReconciliationReportService::class, function ($mock) use ($report, $snapshot): void {
        $mock->shouldReceive('buildReport')->once()->andReturn($report);
        $mock->shouldReceive('persistSnapshot')->once()->with($report, null)->andReturn($snapshot);
    });

    $this->mock(ReconciliationDigestService::class, function ($mock): void {
        $mock->shouldReceive('notifyAdminsOfReport')->once();
    });

    expect(Artisan::call('fund:reconcile', ['--daily' => true, '--force' => true, '--tenants' => ['testing']]))->toBe(0);
});

test('fund reconcile --strict exits failure when the verdict fails', function () {
    $report = failingReconcileReport();
    $snapshot = new ReconciliationSnapshot(['id' => 100]);
    $snapshot->id = 100;

    $this->mock(ReconciliationReportService::class, function ($mock) use ($report, $snapshot): void {
        $mock->shouldReceive('buildReport')->once()->andReturn($report);
        $mock->shouldReceive('persistSnapshot')->once()->with($report, null)->andReturn($snapshot);
    });

    $this->mock(ReconciliationDigestService::class, function ($mock): void {
        $mock->shouldReceive('notifyAdminsOfReport')->once();
    });

    expect(Artisan::call('fund:reconcile', ['--daily' => true, '--force' => true, '--strict' => true, '--tenants' => ['testing']]))->toBe(1);
});
