<?php

declare(strict_types=1);

use App\Models\Tenant\Setting;
use App\Support\LedgerSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->where('group', LedgerSettings::GROUP)->delete();
});

test('ledger settings default manual credit and debit to hidden and edit delete to shown', function () {
    expect(LedgerSettings::showManualCreditDebit())->toBeFalse()
        ->and(LedgerSettings::showSplitReverse())->toBeFalse()
        ->and(LedgerSettings::showEditDelete())->toBeTrue()
        ->and(LedgerSettings::allForForm())->toBe([
            'ledger_show_manual_credit_debit' => false,
            'ledger_show_split_reverse' => false,
            'ledger_show_edit_delete' => true,
        ]);
});

test('ledger settings can enable manual credit and debit from form state', function () {
    LedgerSettings::saveFromForm(['ledger_show_manual_credit_debit' => true]);

    expect(LedgerSettings::showManualCreditDebit())->toBeTrue()
        ->and(Setting::get(LedgerSettings::GROUP, 'show_manual_credit_debit'))->toBe('1')
        ->and(LedgerSettings::showSplitReverse())->toBeFalse();
});

test('ledger settings can disable edit and delete from form state', function () {
    LedgerSettings::saveFromForm(['ledger_show_edit_delete' => false]);

    expect(LedgerSettings::showEditDelete())->toBeFalse()
        ->and(Setting::get(LedgerSettings::GROUP, 'show_edit_delete'))->toBe('0')
        ->and(LedgerSettings::showManualCreditDebit())->toBeFalse();
});

test('ledger settings can enable split and reverse from form state', function () {
    LedgerSettings::saveFromForm(['ledger_show_split_reverse' => true]);

    expect(LedgerSettings::showSplitReverse())->toBeTrue()
        ->and(Setting::get(LedgerSettings::GROUP, 'show_split_reverse'))->toBe('1')
        ->and(LedgerSettings::showManualCreditDebit())->toBeFalse();
});
