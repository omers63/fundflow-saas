<?php

declare(strict_types=1);

function extractKeys(string $file): array
{
    $content = file_get_contents($file);

    preg_match_all('/__\(\s*([\'"])(.*?)\1/s', $content, $matches);
    preg_match_all('/trans_choice\(\s*([\'"])(.*?)\1/s', $content, $choiceMatches);

    return array_merge($matches[2] ?? [], $choiceMatches[2] ?? []);
}

$paths = array_merge(
    glob(__DIR__.'/../app/Filament/Tenant/Resources/MasterAccounts/**/*.php') ?: [],
    glob(__DIR__.'/../app/Filament/Tenant/Resources/MasterAccounts/**/**/*.php') ?: [],
    [
        __DIR__.'/../app/Services/MasterAccountsInsightsService.php',
        __DIR__.'/../app/Services/AccountDetailInsightsService.php',
        __DIR__.'/../app/Filament/Tenant/Widgets/MasterAccountsInsightsWidget.php',
        __DIR__.'/../app/Filament/Tenant/Widgets/AccountDetailInsightsWidget.php',
        __DIR__.'/../app/Filament/Support/AccountTransactionManualAdjustmentHeaderActions.php',
        __DIR__.'/../app/Filament/Support/MasterExpenseHeaderActions.php',
        __DIR__.'/../app/Filament/Support/MasterFeesHeaderActions.php',
        __DIR__.'/../app/Filament/Support/MasterInvestHeaderActions.php',
        __DIR__.'/../app/Filament/Support/ViewActions/ViewAccountTransactionAction.php',
        __DIR__.'/../app/Filament/Support/AccountTransactionTypeColumn.php',
        __DIR__.'/../app/Filament/Support/AccountTransactionTypeFilter.php',
    ],
    glob(__DIR__.'/../resources/views/filament/tenant/widgets/*master*') ?: [],
    glob(__DIR__.'/../resources/views/filament/tenant/widgets/*account-detail*') ?: [],
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
