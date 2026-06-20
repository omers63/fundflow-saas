<?php

declare(strict_types=1);

$supplements = [
    require __DIR__.'/../lang/member_portal_ar.php',
    require __DIR__.'/../lang/tenant_bank_accounts_ar.php',
    require __DIR__.'/../lang/tenant_master_accounts_ar.php',
];
$arPath = __DIR__.'/../lang/ar.json';
$ar = json_decode((string) file_get_contents($arPath), true, 512, JSON_THROW_ON_ERROR);

$merged = 0;

foreach ($supplements as $supplement) {
    foreach ($supplement as $key => $value) {
        $ar[$key] = $value;
    }

    $merged += count($supplement);
}

ksort($ar, SORT_STRING);

file_put_contents(
    $arPath,
    json_encode($ar, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
);

echo 'Merged '.$merged." Arabic supplement translations.\n";
