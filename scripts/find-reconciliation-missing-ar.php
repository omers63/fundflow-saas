<?php

declare(strict_types=1);

$roots = [
    __DIR__.'/../app/Filament/Tenant/Pages/ReconciliationOverviewPage.php',
    __DIR__.'/../resources/views/filament/tenant/pages/reconciliation.blade.php',
    __DIR__.'/../app/Filament/Tenant/Resources/ReconciliationExceptions/**/*.php',
    __DIR__.'/../app/Services/ReconciliationReportService.php',
    __DIR__.'/../app/Services/ReconciliationService.php',
    __DIR__.'/../app/Services/ReconciliationResolutionService.php',
    __DIR__.'/../app/Services/ReconciliationCorrectionService.php',
    __DIR__.'/../resources/views/pdf/reconciliation-snapshot.blade.php',
];

$paths = [];

foreach ($roots as $root) {
    if (is_file($root)) {
        $paths[] = $root;

        continue;
    }

    $paths = array_merge($paths, glob($root) ?: []);
}

/** @var array<string, string> $ar */
$ar = json_decode((string) file_get_contents(__DIR__.'/../lang/ar.json'), true, 512, JSON_THROW_ON_ERROR);

$keys = [];

foreach ($paths as $path) {
    $content = (string) file_get_contents($path);

    if (preg_match_all("/__\\(['\"]([^'\"\\n]{2,240})['\"]/", $content, $matches)) {
        foreach ($matches[1] as $key) {
            $keys[$key] = true;
        }
    }

    if (preg_match_all("/trans_choice\\(['\"]([^'\"\\n]{2,240})['\"]/", $content, $choiceMatches)) {
        foreach ($choiceMatches[1] as $key) {
            $keys[$key] = true;
        }
    }
}

$missing = [];

foreach (array_keys($keys) as $key) {
    if (! array_key_exists($key, $ar)) {
        $missing[] = $key;
    }
}

sort($missing);

echo count($missing)." missing keys\n";

foreach ($missing as $key) {
    echo $key."\n";
}
