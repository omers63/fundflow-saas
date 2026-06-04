<?php

declare(strict_types=1);

use App\Support\ArabicDisplaySettings;
use App\Support\ArabicTypography;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

it('detects arabic script in names', function () {
    expect(ArabicTypography::containsArabic('Ahmed Al-Rashid'))->toBeFalse()
        ->and(ArabicTypography::containsArabic('محمد أحمد'))->toBeTrue()
        ->and(ArabicTypography::containsArabic('John · محمد'))->toBeTrue();
});

it('wraps multi word arabic names in a single bdi', function () {
    $html = ArabicTypography::display('محمد أحمد')->toHtml();

    expect($html)
        ->toMatch('/<bdi dir="rtl" lang="ar" class="ff-arabic-name">محمد أحمد<\/bdi>/')
        ->and(substr_count($html, '<bdi'))->toBe(1);
});

it('groups arabic words in mixed script labels', function () {
    $html = ArabicTypography::display('Ali · محمد أحمد')->toHtml();

    expect($html)
        ->toContain('Ali')
        ->toMatch('/<bdi dir="rtl" lang="ar" class="ff-arabic-name">محمد أحمد<\/bdi>/')
        ->and(substr_count($html, '<bdi'))->toBe(1);
});

it('wraps arabic segments with bdi when enhanced style is off', function () {
    ArabicDisplaySettings::save([
        'arabic_display_font' => ArabicDisplaySettings::FONT_NOTO_SANS,
        'arabic_enhanced_name_style' => false,
    ]);

    $html = ArabicTypography::display('Ali · فاطمة')->toHtml();

    expect($html)
        ->toContain('<bdi')
        ->toContain('dir="rtl"')
        ->toContain('ff-arabic-name')
        ->toContain('فاطمة')
        ->toContain('Ali');
});

it('wraps arabic segments with name class when enhanced style is on', function () {
    ArabicDisplaySettings::save([
        'arabic_display_font' => ArabicDisplaySettings::FONT_NASKH,
        'arabic_enhanced_name_style' => true,
    ]);

    $html = ArabicTypography::display('محمد')->toHtml();

    expect($html)->toContain('ff-arabic-name')->toContain('محمد');
});

it('escapes html in names', function () {
    $html = ArabicTypography::display('<script>alert(1)</script>')->toHtml();

    expect($html)->not->toContain('<script>');
});

it('identifies person name columns', function () {
    expect(ArabicTypography::isPersonNameColumn('member.name'))->toBeTrue()
        ->and(ArabicTypography::isPersonNameColumn('amount'))->toBeFalse();
});
