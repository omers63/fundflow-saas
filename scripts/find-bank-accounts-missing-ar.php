<?php

declare(strict_types=1);

function extractKeys(string $file): array
{
    $content = file_get_contents($file);

    preg_match_all('/__\(\s*([\'"])(.*?)\1/s', $content, $matches);

    return $matches[2] ?? [];
}

$paths = array_merge(
    glob(__DIR__.'/../app/Filament/Tenant/Resources/BankAccounts/**/*.php') ?: [],
    glob(__DIR__.'/../app/Filament/Tenant/Resources/BankAccounts/**/**/*.php') ?: [],
    glob(__DIR__.'/../resources/views/filament/tenant/**/bank*.blade.php') ?: [],
    [
        __DIR__.'/../app/Services/BankAccountsInsightsService.php',
        __DIR__.'/../app/Filament/Tenant/Widgets/BankAccountsInsightsWidget.php',
        __DIR__.'/../app/Filament/Support/BankWorkspaceImportTableHeaderActions.php',
    ],
);

$keys = [];

foreach ($paths as $path) {
    foreach (extractKeys($path) as $key) {
        $key = trim($key);

        if ($key !== '') {
            $keys[$key] = true;
        }
    }
}

$ar = json_decode((string) file_get_contents(__DIR__.'/../lang/ar.json'), true, 512, JSON_THROW_ON_ERROR);
$missing = array_keys(array_filter($keys, static fn (string $key): bool => ! array_key_exists($key, $ar), ARRAY_FILTER_USE_KEY));
sort($missing);

foreach ($missing as $key) {
    echo $key, PHP_EOL;
}

echo PHP_EOL, 'Total missing: ', count($missing), PHP_EOL;
