<?php

declare(strict_types=1);

use App\Filament\Tenant\Support\BankClearingTabRegistry;
use Illuminate\Support\Facades\App;

beforeEach(function () {
    App::setLocale('en');
});

it('exposes the three primary bank clearing tabs', function () {
    expect(BankClearingTabRegistry::tabs())->toBe([
        'queue' => 'Work queue',
        'ledger' => 'Bank ledger',
        'history' => 'Import history',
    ]);
});

it('normalizes legacy tab aliases to the new registry keys', function () {
    expect(BankClearingTabRegistry::normalizeTab('clearance'))->toBe('queue')
        ->and(BankClearingTabRegistry::normalizeTab('imports'))->toBe('queue')
        ->and(BankClearingTabRegistry::normalizeTab('transactions'))->toBe('queue')
        ->and(BankClearingTabRegistry::normalizeTab('statements'))->toBe('history')
        ->and(BankClearingTabRegistry::normalizeTab('ledger'))->toBe('ledger')
        ->and(BankClearingTabRegistry::normalizeTab(null))->toBe('queue')
        ->and(BankClearingTabRegistry::normalizeTab('invalid'))->toBe('queue');
});

it('maps legacy tabs to queue filters', function () {
    expect(BankClearingTabRegistry::legacyTabQueueFilter('clearance'))->toBe('operations')
        ->and(BankClearingTabRegistry::legacyTabQueueFilter('imports'))->toBe('bank_file')
        ->and(BankClearingTabRegistry::legacyTabQueueFilter('transactions'))->toBe('bank_file')
        ->and(BankClearingTabRegistry::legacyTabQueueFilter('ledger'))->toBeNull();
});

it('normalizes queue filters and history sections', function () {
    expect(BankClearingTabRegistry::normalizeQueueFilter('bank_file'))->toBe('bank_file')
        ->and(BankClearingTabRegistry::normalizeQueueFilter('operations'))->toBe('operations')
        ->and(BankClearingTabRegistry::normalizeQueueFilter(null))->toBe('all')
        ->and(BankClearingTabRegistry::normalizeHistorySection('closed'))->toBe('closed')
        ->and(BankClearingTabRegistry::normalizeHistorySection(null))->toBe('batches');
});
