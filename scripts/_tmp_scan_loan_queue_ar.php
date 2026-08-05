<?php

declare(strict_types=1);

function extractKeys(string $content): array
{
    $keys = [];
    if (preg_match_all("/(?:__|trans_choice)\\(\\s*'((?:\\\\'|[^'])*)'/", $content, $m)) {
        foreach ($m[1] as $k) {
            $keys[] = str_replace("\\'", "'", $k);
        }
    }

    return $keys;
}

$files = [
    'resources/views/filament/tenant/pages/loan-queue-workbench.blade.php',
    'app/Filament/Tenant/Pages/LoanQueueWorkbenchPage.php',
    'app/Filament/Support/LoanQueueTable.php',
    'app/Filament/Tenant/Pages/LoanQueue.php',
    'app/Filament/Tenant/Clusters/LoanQueuePage.php',
];

$ar = json_decode((string) file_get_contents('lang/ar.json'), true, 512, JSON_THROW_ON_ERROR);
$missing = [];

foreach ($files as $file) {
    if (! is_file($file)) {
        continue;
    }
    foreach (extractKeys((string) file_get_contents($file)) as $key) {
        if (mb_strlen($key) < 2) {
            continue;
        }
        if (! array_key_exists($key, $ar)) {
            $missing[$key] = basename($file);
        }
    }
}

ksort($missing);
echo count($missing)." missing\n";
foreach ($missing as $k => $f) {
    echo "[{$f}] {$k}\n";
}
