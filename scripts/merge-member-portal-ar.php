<?php

declare(strict_types=1);

$supplement = require __DIR__.'/../lang/member_portal_ar.php';
$arPath = __DIR__.'/../lang/ar.json';
$ar = json_decode((string) file_get_contents($arPath), true, 512, JSON_THROW_ON_ERROR);

foreach ($supplement as $key => $value) {
    $ar[$key] = $value;
}

ksort($ar, SORT_STRING);

file_put_contents(
    $arPath,
    json_encode($ar, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)."\n",
);

echo 'Merged '.count($supplement)." member portal translations.\n";
