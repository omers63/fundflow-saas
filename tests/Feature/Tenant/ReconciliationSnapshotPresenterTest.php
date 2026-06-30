<?php

declare(strict_types=1);

use App\Models\Tenant\ReconciliationSnapshot;
use App\Support\Reconciliation\ReconciliationSnapshotPresenter as Presenter;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();
});

test('snapshot presenter orders checks by severity', function () {
    $ordered = Presenter::orderedChecks([
        'ledger_balances' => ['severity' => 'ok'],
        'global_trial' => ['severity' => 'warning'],
        'orphan_loan_accounts' => ['severity' => 'critical'],
    ]);

    expect(array_column($ordered, 'key'))->toBe([
        'orphan_loan_accounts',
        'global_trial',
        'ledger_balances',
    ]);
});

test('snapshot presenter builds loan mismatch detail sections', function () {
    $sections = Presenter::checkDetailSections('active_loans_schedule_vs_ledger', [
        'severity' => 'warning',
        'mismatch_count' => 1,
        'mismatches' => [[
            'loan_id' => 180,
            'member' => 'Test Member',
            'ledger_outstanding' => 35_000.0,
            'ledger_expected' => 35_000.0,
            'scheduled_outstanding' => 36_000.0,
            'partial_paid_ahead' => 1_000.0,
            'delta' => 0.0,
        ]],
    ]);

    expect($sections)->not->toBeEmpty()
        ->and($sections[0]['format'])->toBe('metrics')
        ->and($sections[1]['title'])->toBe(__('Mismatch details'))
        ->and($sections[1]['rows'][0]['loan_id'])->toBe(180);
});

test('snapshot presenter formats mode labels', function () {
    expect(Presenter::modeLabel(ReconciliationSnapshot::MODE_REALTIME))->toBe(__('Real-time'))
        ->and(Presenter::modeLabel(ReconciliationSnapshot::MODE_DAILY))->toBe(__('Daily'));
});
