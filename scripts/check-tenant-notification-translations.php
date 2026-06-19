<?php

declare(strict_types=1);

/** @var array<string, string> $ar */
$ar = json_decode((string) file_get_contents(__DIR__.'/../lang/ar.json'), true, 512, JSON_THROW_ON_ERROR);

$strings = [];

foreach (glob(__DIR__.'/../app/Notifications/Tenant/**/*.php') ?: [] as $file) {
    collectStrings($file, $strings);
}

foreach (glob(__DIR__.'/../app/Notifications/Tenant/*.php') ?: [] as $file) {
    collectStrings($file, $strings);
}

$extraFiles = [
    __DIR__.'/../app/Services/Tenant/MemberPortalNotificationService.php',
    __DIR__.'/../app/Services/Tenant/MemberRequestService.php',
    __DIR__.'/../app/Services/Tenant/DirectMessagingService.php',
];

foreach ($extraFiles as $file) {
    if (is_file($file)) {
        collectStrings($file, $strings);
    }
}

function collectStrings(string $file, array &$strings): void
{
    preg_match_all("/__\\('((?:[^'\\\\]|\\\\.)*)'/", (string) file_get_contents($file), $matches);

    foreach ($matches[1] as $string) {
        $strings[stripcslashes($string)] = true;
    }
}

$missing = array_keys(array_filter($strings, fn (string $key): bool => ! isset($ar[$key]), ARRAY_FILTER_USE_KEY));
sort($missing);

echo count($missing)." missing tenant notification strings\n";

foreach ($missing as $string) {
    echo $string."\n";
}
