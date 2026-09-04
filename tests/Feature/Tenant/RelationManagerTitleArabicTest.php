<?php

declare(strict_types=1);

use Tests\Support\AdminPortalTranslationCatalog;

test('filament relation manager tab titles have arabic translations', function (): void {
    /** @var array<string, string> $arabic */
    $arabic = json_decode((string) file_get_contents(base_path('lang/ar.json')), true, 512, JSON_THROW_ON_ERROR);

    $titlePattern = "/protected static \\?string \\\$title\\s*=\\s*'([^']+)'/";
    $titles = [];

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(app_path('Filament'), FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (!$file->isFile() || !str_ends_with($file->getFilename(), '.php')) {
            continue;
        }

        $contents = (string) file_get_contents($file->getPathname());

        if (!preg_match_all($titlePattern, $contents, $matches)) {
            continue;
        }

        foreach ($matches[1] as $title) {
            $titles[$title] = true;
        }
    }

    expect($titles)->not->toBeEmpty();

    foreach (array_keys($titles) as $title) {
        expect($arabic)->toHaveKey($title)
            ->and(AdminPortalTranslationCatalog::looksArabic($arabic[$title]))->toBeTrue();
    }
});

test('member view cycle history tab title translates to arabic', function (): void {
    /** @var array<string, string> $arabic */
    $arabic = json_decode((string) file_get_contents(base_path('lang/ar.json')), true, 512, JSON_THROW_ON_ERROR);

    expect($arabic)->toHaveKey('Cycle history')
        ->and($arabic['Cycle history'])->toBe('سجل الدورات');

    app()->setLocale('ar');

    expect(__('Cycle history'))->toBe('سجل الدورات');
});
