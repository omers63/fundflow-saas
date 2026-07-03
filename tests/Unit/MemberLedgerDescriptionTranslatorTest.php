<?php

declare(strict_types=1);

use App\Models\Tenant\Transaction;
use App\Support\MemberDateDisplay;
use App\Support\MemberLedgerDescriptionTranslator;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

test('localizes English contribution descriptions into Arabic', function () {
    app()->setLocale('ar');

    $localized = MemberLedgerDescriptionTranslator::localize('Contribution — May 2026');

    expect($localized)
        ->toContain('مساهمة')
        ->not->toBe('Contribution — May 2026');
});

test('localizes English EMI late fee descriptions into Arabic', function () {
    app()->setLocale('ar');

    $localized = MemberLedgerDescriptionTranslator::localize('EMI late fee — loan #12 inst. 3');

    expect($localized)
        ->toContain('رسوم')
        ->toContain('12')
        ->toContain('3');
});

test('localizes deposit by member descriptions into Arabic', function () {
    app()->setLocale('ar');

    expect(MemberLedgerDescriptionTranslator::localize('Deposit by Ahmed Ali'))
        ->toContain('إيداع')
        ->toContain('Ahmed Ali');
});

test('strips redundant member parenthetical when name is already in description', function () {
    app()->setLocale('ar');

    $name = 'خديجة عبدالحكيم أميرشاه عصمت';
    $duplicated = $name.' (عضو '.$name.')';

    expect(MemberLedgerDescriptionTranslator::localize($duplicated))->toBe($name)
        ->and(MemberLedgerDescriptionTranslator::localize('Deposit by Ahmed Ali (Ahmed Ali)'))
        ->toBe(__('Deposit by :name', ['name' => 'Ahmed Ali']));
});

test('descriptionAlreadyContainsMemberName avoids false positives for short month names', function () {
    expect(MemberLedgerDescriptionTranslator::descriptionAlreadyContainsMemberName('Contribution — May 2026', 'May'))
        ->toBeFalse()
        ->and(MemberLedgerDescriptionTranslator::descriptionAlreadyContainsMemberName('Deposit by Ahmed Ali', 'Ahmed Ali'))
        ->toBeTrue();
});

test('member facing description uses localized fallback for stored English text', function () {
    app()->setLocale('ar');

    $transaction = new Transaction([
        'description' => 'Contribution — Jan 2026',
        'type' => 'debit',
    ]);

    expect($transaction->memberFacingDescription())
        ->toContain('مساهمة')
        ->not->toBe('Contribution — Jan 2026');
});

test('localizes dependent allocation transfer descriptions into Arabic', function () {
    app()->setLocale('ar');

    $localized = MemberLedgerDescriptionTranslator::localize(
        'Transfer to Sara Ali — Allocation — May 2026',
    );

    expect($localized)
        ->toContain('تحويل إلى')
        ->toContain('Sara Ali')
        ->toContain('تخصيص')
        ->not->toContain('Transfer to');
});

test('member facing description localizes parent dependent allocation debit', function () {
    app()->setLocale('ar');

    $transaction = new Transaction([
        'description' => 'Transfer to Sara Ali — Allocation — May 2026',
        'type' => 'debit',
    ]);

    expect($transaction->memberFacingDescription())
        ->toContain('تحويل إلى')
        ->and($transaction->memberActivityCategoryLabel())->toBe(__('Allocation'));
});

test('displayDescription strips redundant member parenthetical for admin display', function () {
    app()->setLocale('ar');

    $name = 'خديجة عبدالحكيم أميرشاه عصمت';
    $transaction = new Transaction([
        'id' => 42,
        'description' => $name.' (عضو '.$name.')',
    ]);

    expect($transaction->displayDescription())->toBe($name);
});

test('displayDescription localizes English stored descriptions', function () {
    app()->setLocale('ar');

    $transaction = new Transaction([
        'description' => 'Contribution — Jan 2026',
    ]);

    expect($transaction->displayDescription())
        ->toContain('مساهمة')
        ->not->toBe('Contribution — Jan 2026');
});

test('member date display uses western digits in Arabic locale', function () {
    app()->setLocale('ar');

    $formatted = MemberDateDisplay::format(now()->setDate(2026, 5, 10), 'M j, Y');

    expect($formatted)
        ->toContain('2026')
        ->not->toMatch('/[٠-٩]/u');
});
