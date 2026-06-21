<?php

declare(strict_types=1);

require __DIR__.'/../vendor/autoload.php';

$roots = [
    __DIR__.'/../app/Filament',
    __DIR__.'/../resources/views/filament',
];

/** @var list<string> $findings */
$findings = [];

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

        if (! str_ends_with($path, '.php') && ! str_ends_with($path, '.blade.php')) {
            continue;
        }

        scanFile($path, $findings);
    }
}

sort($findings);

echo count($findings)." potential hardcoded English string(s)\n";

foreach ($findings as $finding) {
    echo $finding."\n";
}

/**
 * @param  list<string>  $findings
 */
function scanFile(string $path, array &$findings): void
{
    $lines = file($path, FILE_IGNORE_NEW_LINES);

    if ($lines === false) {
        return;
    }

    $relative = str_replace(dirname(__DIR__).'/', '', $path);

    foreach ($lines as $index => $line) {
        $lineNumber = $index + 1;
        $trimmed = trim($line);

        if ($trimmed === '' || str_starts_with($trimmed, '//') || str_starts_with($trimmed, '*')) {
            continue;
        }

        if (containsTranslationHelper($trimmed)) {
            continue;
        }

        if (preg_match_all("/(?:placeholder|modalHeading|modalDescription|helperText|title|body|heading|description|content)\(\s*['\"]([^'\"\\n]{3,120})['\"]/", $trimmed, $matches)) {
            foreach ($matches[1] as $string) {
                if (isLikelyHardcodedEnglish($string)) {
                    $findings[] = "{$relative}:{$lineNumber}: {$string}";
                }
            }
        }

        if (preg_match_all("/(?:Section|Notification)::make\(\s*['\"]([^'\"\\n]{3,120})['\"]/", $trimmed, $sectionMatches)) {
            foreach ($sectionMatches[1] as $string) {
                if (isLikelyHardcodedEnglish($string)) {
                    $findings[] = "{$relative}:{$lineNumber}: {$string}";
                }
            }
        }

        if (str_ends_with($path, '.blade.php') && preg_match_all("/>\s*([A-Z][A-Za-z0-9 ,.'\\-]{3,80})\s*</", $trimmed, $bladeMatches)) {
            foreach ($bladeMatches[1] as $string) {
                if (isLikelyHardcodedEnglish($string)) {
                    $findings[] = "{$relative}:{$lineNumber}: {$string}";
                }
            }
        }

        if (preg_match_all("/text-(?:left|right)\b/", $trimmed, $alignMatches) && ! str_contains($trimmed, 'ltr:') && ! str_contains($trimmed, 'rtl:')) {
            $findings[] = "{$relative}:{$lineNumber}: physical alignment class (use text-start/text-end)";
        }
    }
}

function containsTranslationHelper(string $line): bool
{
    return (bool) preg_match('/__\(|@lang\(|Lang::ui\(|trans\(|trans_choice\(/', $line);
}

function isLikelyHardcodedEnglish(string $string): bool
{
    $string = trim($string);

    if ($string === '' || ! preg_match('/[A-Za-z]{3,}/', $string)) {
        return false;
    }

    if (preg_match('/^[\d\s\-_:.\\/\\#@]+$/', $string)) {
        return false;
    }

    if (str_contains($string, '(?P<') || str_contains($string, '(?<')) {
        return false;
    }

    if (preg_match('/^(https?:|heroicon|fi-|wire:|x-|class=|YYYY|SA\d)/i', $string)) {
        return false;
    }

    if (preg_match('/^(and|or|the|from|with|using|before|after|at|in|on|to|for|not|optional|required|default|active|status|type|format|id|csv|pdf|json|excel|sms|emi)$/i', $string)) {
        return false;
    }

    return (bool) preg_match('/\b(the|and|for|with|from|your|this|will|are|has|have|must|cannot|please|select|enter|choose|click|member|loan|bank|cash|fund)\b/i', $string);
}
