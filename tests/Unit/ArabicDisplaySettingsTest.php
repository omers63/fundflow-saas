<?php

declare(strict_types=1);

use App\Support\ArabicDisplaySettings;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

it('defaults to noto sans arabic without enhanced names', function () {
    ArabicDisplaySettings::save([
        'arabic_display_font' => ArabicDisplaySettings::FONT_NOTO_SANS,
        'arabic_enhanced_name_style' => false,
    ]);

    expect(ArabicDisplaySettings::fontPreset())->toBe(ArabicDisplaySettings::FONT_NOTO_SANS)
        ->and(ArabicDisplaySettings::enhancedNameStyle())->toBeFalse()
        ->and(ArabicDisplaySettings::fontFamilyCss())->toContain('Noto Sans Arabic');
});

it('persists tajawal and enhanced name style', function () {
    ArabicDisplaySettings::save([
        'arabic_display_font' => ArabicDisplaySettings::FONT_TAJAWAL,
        'arabic_enhanced_name_style' => true,
    ]);

    expect(ArabicDisplaySettings::fontPreset())->toBe(ArabicDisplaySettings::FONT_TAJAWAL)
        ->and(ArabicDisplaySettings::enhancedNameStyle())->toBeTrue()
        ->and(ArabicDisplaySettings::bunnyFontsFamilyParam())->toContain('tajawal');
});
