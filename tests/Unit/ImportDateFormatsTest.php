<?php

use App\Support\ImportDateFormats;
use Tests\TestCase;

uses(TestCase::class);

test('normalize accepts legacy single string and json', function () {
    expect(ImportDateFormats::normalize('Y-m-d'))->toBe(['Y-m-d'])
        ->and(ImportDateFormats::normalize('["d/m/Y","Y-m-d"]'))->toBe(['d/m/Y', 'Y-m-d'])
        ->and(ImportDateFormats::normalize(['m/d/Y', 'Y-m-d']))->toBe(['m/d/Y', 'Y-m-d']);
});

test('contradictionMessage flags day-first vs month-first slash formats', function () {
    expect(ImportDateFormats::contradictionMessage(['d/m/Y', 'm/d/Y']))->not->toBeNull()
        ->and(ImportDateFormats::contradictionMessage(['Y-m-d', 'd/m/Y']))->toBeNull()
        ->and(ImportDateFormats::contradictionMessage(['d/m/Y', 'd-m-Y']))->toBeNull();
});

test('parse tries formats in order', function () {
    expect(ImportDateFormats::parse('2026-05-01', ['Y-m-d', 'd/m/Y'])->toDateString())->toBe('2026-05-01')
        ->and(ImportDateFormats::parse('01/05/2026', ['Y-m-d', 'd/m/Y'])->toDateString())->toBe('2026-05-01');
});

test('parse uses first matching format when multiple are allowed', function () {
    expect(ImportDateFormats::parse('15/06/2023', ['d/m/Y', 'Y-m-d'])->toDateString())->toBe('2023-06-15');
});
