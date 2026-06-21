<?php

declare(strict_types=1);

use Tests\Support\AppTranslationCatalog;

test('application translation keys have arabic entries in ar.json', function () {
    expect(AppTranslationCatalog::missingArabicKeys())->toBeEmpty();
});

test('application translation keys have arabic script in ar.json', function () {
    expect(AppTranslationCatalog::untranslatedKeys())->toBeEmpty();
});

test('application translation catalogue keys are syntactically valid', function () {
    foreach (AppTranslationCatalog::translationKeys() as $key) {
        expect(AppTranslationCatalog::isValidKey($key))->toBeTrue();
    }
});
