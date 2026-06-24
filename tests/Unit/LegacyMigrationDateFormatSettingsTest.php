<?php

declare(strict_types=1);

use App\Models\Tenant\Setting;
use App\Support\LegacyMigrationDateFormatSettings;
use App\Support\LegacyMigrationDateParser;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->where('group', LegacyMigrationDateFormatSettings::GROUP)->delete();
});

test('legacy migration date settings default to iso then us slash format', function () {
    expect(LegacyMigrationDateFormatSettings::formats())->toBe(['Y-m-d', 'm/d/Y'])
        ->and(LegacyMigrationDateFormatSettings::slashDateFormat())->toBe('m/d/Y');
});

test('legacy migration date parser uses us slash format by default', function () {
    expect(LegacyMigrationDateParser::parse('11/3/2025', 2)->toDateString())->toBe('2025-11-03')
        ->and(LegacyMigrationDateParser::parse('6/2/2025', 2)->toDateString())->toBe('2025-06-02')
        ->and(LegacyMigrationDateParser::parse('2/25/2016', 2)->toDateString())->toBe('2016-02-25')
        ->and(LegacyMigrationDateParser::parse('2025-10-01', 2)->toDateString())->toBe('2025-10-01');
});

test('legacy migration date parser respects european slash setting', function () {
    LegacyMigrationDateFormatSettings::saveSlashDateFormat('d/m/Y');

    expect(LegacyMigrationDateParser::parse('11/3/2025', 2)->toDateString())->toBe('2025-03-11')
        ->and(LegacyMigrationDateParser::parse('8/10/2014', 2)->toDateString())->toBe('2014-10-08');
});

test('legacy migration date parser and import service share the same parsed value', function () {
    $value = '11/3/2025';

    expect(LegacyMigrationDateParser::parseValue($value)->toDateString())
        ->toBe(LegacyMigrationDateParser::parse($value, 2)->toDateString())
        ->toBe('2025-11-03');
});

test('legacy migration date settings reject conflicting slash formats', function () {
    expect(fn () => LegacyMigrationDateFormatSettings::saveFormats(['Y-m-d', 'm/d/Y', 'd/m/Y']))
        ->toThrow(InvalidArgumentException::class);
});
