<?php

declare(strict_types=1);

use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\ReconciliationSnapshot;
use App\Support\Reconciliation\ReconciliationHealthSummary;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('health summary reports critical when open critical exceptions exist', function () {
    ReconciliationException::query()->delete();

    ReconciliationException::create([
        'exception_code' => 'MASTER_CASH_POOL_DRIFT',
        'domain' => 'master_account',
        'severity' => 'critical',
        'status' => ReconciliationException::STATUS_OPEN,
        'raised_at' => now(),
    ]);

    $summary = app(ReconciliationHealthSummary::class)->summarize(
        null,
        1,
        1,
        0,
        0,
        null,
    );

    expect($summary['status'])->toBe(ReconciliationHealthSummary::STATUS_CRITICAL)
        ->and($summary['status_label'])->toBe(__('Critical attention needed'));
});

test('health summary reports pass when snapshot passes and queue is clear', function () {
    $snapshot = ReconciliationSnapshot::create([
        'mode' => ReconciliationSnapshot::MODE_REALTIME,
        'as_of' => now(),
        'is_passing' => true,
        'critical_issues' => 0,
        'warnings' => 0,
        'summary' => [],
        'report' => ['verdict' => ['pass' => true]],
    ]);

    $summary = app(ReconciliationHealthSummary::class)->summarize(
        $snapshot,
        0,
        0,
        0,
        0,
        null,
    );

    expect($summary['status'])->toBe(ReconciliationHealthSummary::STATUS_PASS)
        ->and($summary['status_label'])->toBe(__('In balance'));
});

test('health summary reports attention when warnings exist without critical issues', function () {
    $summary = app(ReconciliationHealthSummary::class)->summarize(
        null,
        2,
        0,
        2,
        0,
        null,
    );

    expect($summary['status'])->toBe(ReconciliationHealthSummary::STATUS_ATTENTION);
});
