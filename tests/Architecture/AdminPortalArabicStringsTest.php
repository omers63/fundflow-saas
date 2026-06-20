<?php

declare(strict_types=1);

use Tests\Support\AdminPortalTranslationCatalog;

test('required admin portal redesign strings have arabic translations', function () {
    expect(AdminPortalTranslationCatalog::untranslatedRequiredKeys())->toBeEmpty();
});

test('admin portal translation catalogue keys are syntactically valid', function () {
    foreach (AdminPortalTranslationCatalog::translationKeys() as $key) {
        expect(AdminPortalTranslationCatalog::isValidKey($key))->toBeTrue();
    }
});
