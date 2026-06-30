<?php

declare(strict_types=1);

use App\Support\MasterReserveLedgerDirection;
use Tests\TestCase;

uses(TestCase::class);

test('reserve import types normalize to credit and debit', function () {
    expect(MasterReserveLedgerDirection::normalizeImportType('credit'))->toBe('credit')
        ->and(MasterReserveLedgerDirection::normalizeImportType('debit'))->toBe('debit')
        ->and(MasterReserveLedgerDirection::normalizeImportType('fund'))->toBe('credit')
        ->and(MasterReserveLedgerDirection::normalizeImportType('disburse'))->toBe('debit');
});

test('reserve workflow routing maps credit and debit', function () {
    expect(MasterReserveLedgerDirection::workflowFromLedgerType('credit'))->toBe('in')
        ->and(MasterReserveLedgerDirection::workflowFromLedgerType('debit'))->toBe('out');
});
