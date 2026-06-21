<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$roots = [
    __DIR__.'/../app',
    __DIR__.'/../resources/views',
];

/** @var array<string, string> $ar */
$ar = json_decode((string) file_get_contents(__DIR__.'/../lang/ar.json'), true, 512, JSON_THROW_ON_ERROR);

$keys = [];

foreach ($roots as $root) {
    if (! is_dir($root)) {
        continue;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if (! $file->isFile()) {
            continue;
        }

        $path = $file->getPathname();

        if (str_contains($path, '/vendor/') || str_contains($path, '/storage/')) {
            continue;
        }

        if (! preg_match('/\.(php|blade\.php)$/', $file->getFilename())) {
            continue;
        }

        collectKeysFromFile($path, $keys);
    }
}

$missing = [];

foreach (array_keys($keys) as $key) {
    if (! array_key_exists($key, $ar)) {
        $missing[] = $key;
    }
}

sort($missing);

echo count($missing).' missing of '.count($keys)." total keys\n";

foreach ($missing as $key) {
    echo $key."\n";
}

/**
 * @param  array<string, true>  $keys
 */
function collectKeysFromFile(string $path, array &$keys): void
{
    $content = (string) file_get_contents($path);

    $pattern = '/(?:__|@lang|Lang::ui|trans|trans_choice)\(\s*(\'(?:\\\\.|[^\'])*\'|"(?:\\\\.|[^"])*")/';

    if (! preg_match_all($pattern, $content, $matches)) {
        return;
    }

    foreach ($matches[1] as $quoted) {
        $key = stripcslashes(substr($quoted, 1, -1));

        if (isValidKey($key)) {
            $keys[$key] = true;
        }
    }
}

function isValidKey(string $key): bool
{
    $key = trim($key);

    if ($key === '' || str_contains($key, "\n")) {
        return false;
    }

    if (str_starts_with($key, '(') || str_contains($key, '<') || str_contains($key, 'column index')) {
        return false;
    }

    if (! preg_match('/^(\p{L}|:|\d)/u', $key)) {
        return false;
    }

    if (preg_match('/^[\(\)\[\]\.,;:!?#@&*\/\\\\]+$/', $key)) {
        return false;
    }

    if (str_ends_with($key, '\\')) {
        return false;
    }

    if (preg_match('/^(and|or|the|from|with|using|before|after|at|in|on|to|for|not|optional|e\.g\.|tables?|files?|days?|status|period|loans?|members?|monthly|daily|single|male|female|other|required|default|access|error|success|warning|critical|tab|code|term|year|size|type|format|driver|domain)$/i', $key)) {
        return false;
    }

    return strlen($key) >= 3;
}
