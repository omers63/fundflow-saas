<?php

declare(strict_types=1);

use App\Support\Lang;

test('translate ui resolves filament casing variants for arabic', function () {
    app()->setLocale('ar');

    expect(Lang::translateUi('loan'))->toBe('قرض')
        ->and(Lang::translateUi('loans'))->toBe('القروض')
        ->and(Lang::translateUi('motion'))->toBe('مقترح')
        ->and(Lang::translateUi('motions'))->toBe('المقترحات')
        ->and(Lang::translateUi('contribution'))->toBe('مساهمة')
        ->and(Lang::translateUi('contributions'))->toBe('المساهمات')
        ->and(Lang::formatUiLabel('المقترحات'))->toBe('المقترحات')
        ->and(Lang::translateUi('HTTP'))->toBe('HTTP')
        ->and(Lang::translateUi('URL'))->toBe('الرابط')
        ->and(Lang::formatUiLabel('PHP-FPM'))->toBe('PHP-FPM');
});

test('missing translation key handler resolves title-case arabic variants', function () {
    app()->setLocale('ar');

    // Simulate a key that only exists as Title Case in ar.json.
    expect(__('Meeting'))->not->toBe('Meeting')
        ->and(__('meeting'))->not->toBe('meeting');
});
