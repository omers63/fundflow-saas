<?php

declare(strict_types=1);

namespace Tests\Support;

final class AppTranslationCatalog
{
    private static function projectRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private static function arabicCatalogPath(): string
    {
        return self::projectRoot().'/lang/ar.json';
    }

    /**
     * @return array<string, string>
     */
    private static function arabicCatalog(): array
    {
        /** @var array<string, string> $arabic */
        $arabic = json_decode((string) file_get_contents(self::arabicCatalogPath()), true, 512, JSON_THROW_ON_ERROR);

        return $arabic;
    }

    /**
     * @return list<string>
     */
    public static function scanPaths(): array
    {
        $root = self::projectRoot();

        return [
            $root.'/app',
            $root.'/resources/views',
        ];
    }

    /**
     * @return list<string>
     */
    public static function translationKeys(): array
    {
        $keys = [];

        foreach (self::scanPaths() as $path) {
            if (! is_dir($path)) {
                continue;
            }

            $iterator = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            );

            foreach ($iterator as $file) {
                if (! $file->isFile()) {
                    continue;
                }

                $filePath = $file->getPathname();

                if (str_contains($filePath, '/vendor/') || str_contains($filePath, '/storage/')) {
                    continue;
                }

                $filename = $file->getFilename();

                if (! str_ends_with($filename, '.php') && ! str_ends_with($filename, '.blade.php')) {
                    continue;
                }

                self::collectKeysFromFile($filePath, $keys);
            }
        }

        $sorted = array_keys($keys);
        sort($sorted);

        return $sorted;
    }

    /**
     * @param  array<string, true>  $keys
     */
    private static function collectKeysFromFile(string $path, array &$keys): void
    {
        $content = (string) file_get_contents($path);

        $pattern = '/(?:__|@lang|Lang::ui|trans|trans_choice)\(\s*(\'(?:\\\\.|[^\'])*\'|"(?:\\\\.|[^"])*")/';

        if (! preg_match_all($pattern, $content, $matches)) {
            return;
        }

        foreach ($matches[1] as $quoted) {
            $key = stripcslashes(substr($quoted, 1, -1));

            if (self::isValidKey($key)) {
                $keys[$key] = true;
            }
        }
    }

    public static function isValidKey(string $key): bool
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

    /**
     * @return list<string>
     */
    public static function missingArabicKeys(): array
    {
        /** @var array<string, string> $arabic */
        $arabic = self::arabicCatalog();

        $missing = [];

        foreach (self::translationKeys() as $key) {
            if (! array_key_exists($key, $arabic)) {
                $missing[] = $key;
            }
        }

        return $missing;
    }

    /**
     * Keys present in ar.json but not real Arabic copy (same English or no Arabic script).
     *
     * @return list<string>
     */
    public static function untranslatedKeys(): array
    {
        /** @var array<string, string> $arabic */
        $arabic = self::arabicCatalog();

        $untranslated = [];

        foreach (self::translationKeys() as $key) {
            if (! array_key_exists($key, $arabic)) {
                continue;
            }

            if (self::shouldSkipArabicScriptCheck($key)) {
                continue;
            }

            if (! self::looksArabic($arabic[$key])) {
                $untranslated[] = $key;
            }
        }

        return $untranslated;
    }

    public static function looksArabic(string $value): bool
    {
        return (bool) preg_match('/\p{Arabic}/u', $value);
    }

    public static function shouldSkipArabicScriptCheck(string $key): bool
    {
        if (str_starts_with($key, 'filament-') || str_starts_with($key, 'filament::')) {
            return true;
        }

        if (in_array($key, ['CSV', 'PDF', 'JSON', 'SAR', 'YYYY-MM', 'YYYY-MM-DD'], true)) {
            return true;
        }

        if (preg_match('/^Y{2,4}-/', $key)) {
            return true;
        }

        if (preg_match('/^[\dX@.\-]+$/', $key) || preg_match('/@(?:example|email)\.com$/', $key)) {
            return true;
        }

        return false;
    }
}
