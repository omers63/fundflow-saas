<?php

declare(strict_types=1);

namespace Tests\Support;

final class AdminPortalTranslationCatalog
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
            $root.'/app/Filament/Tenant',
            $root.'/resources/views/filament/tenant',
            $root.'/app/Services/TenantDashboardService.php',
        ];
    }

    /**
     * @return list<string>
     */
    public static function translationKeys(): array
    {
        $keys = [];

        foreach (self::scanPaths() as $path) {
            if (is_file($path)) {
                self::collectKeysFromFile($path, $keys);

                continue;
            }

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

                $filename = $file->getFilename();

                if (! str_ends_with($filename, '.php') && ! str_ends_with($filename, '.blade.php')) {
                    continue;
                }

                self::collectKeysFromFile($file->getPathname(), $keys);
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

        if (preg_match_all("/__\\(['\"]([^'\"\\n]{2,160})['\"]/", $content, $matches)) {
            foreach ($matches[1] as $key) {
                if (self::isValidKey($key)) {
                    $keys[$key] = true;
                }
            }
        }

        if (preg_match_all("/Lang::ui\\(['\"]([^'\"\\n]{2,160})['\"]/", $content, $uiMatches)) {
            foreach ($uiMatches[1] as $key) {
                if (self::isValidKey($key)) {
                    $keys[$key] = true;
                }
            }
        }

        if (preg_match_all("/@lang\\(['\"]([^'\"\\n]{2,160})['\"]/", $content, $langMatches)) {
            foreach ($langMatches[1] as $key) {
                if (self::isValidKey($key)) {
                    $keys[$key] = true;
                }
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

        if (! preg_match('/^(\\p{L}|:|\\d)/u', $key)) {
            return false;
        }

        if (preg_match('/^[\\(\\)\\[\\]\\.,;:!?#@&*\\/\\\\]+$/', $key)) {
            return false;
        }

        if (preg_match('/^(and|or|the|from|with|using|before|after|at|in|on|to|for|not|optional|e\\.g\\.|tables?|files?|days?|status|period|loans?|members?|monthly|daily|single|male|female|other|required|default|access|error|success|warning|critical|tab|code|term|year|size|type|format|driver|domain)$/i', $key)) {
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
     * Keys introduced by the admin portal redesign that must have real Arabic copy.
     *
     * @return list<string>
     */
    public static function requiredRedesignKeys(): array
    {
        return [
            'Audit & System',
            'Applications',
            'Alerts',
            'All →',
            'Balanced',
            'Bank Clearing',
            'Bank clearing',
            'Broadcast a bilingual message to a member audience via in-app, SMS, and/or email.',
            'Closed',
            'Collected',
            'Collection calendar',
            'Compose announcement',
            'Compose member announcement',
            'Custom report builder',
            'Cycle: :label',
            'Decision',
            'Disburse',
            'Disbursements',
            'EMI collection calendar',
            'Emergency',
            'Failed',
            'Fund pool health',
            'Fund tier utilisation',
            'Generate report',
            'Late fee tiers',
            'Loan pipeline',
            'Loan portfolio',
            'Manage →',
            'Master cash',
            'Master fund',
            'Messages inbox',
            'Near capacity',
            'No active fund tiers configured',
            'No loans awaiting review',
            'No recent activity',
            'Open queue →',
            'Pending',
            'Pool drift',
            'Pool solvency',
            'Queue clear',
            'Recent activity',
            'Reconciliation summary',
            'Report type',
            'Review',
            'Review reconciliation →',
            'Standard',
            'System workspace',
            'Tier 1 (day 3+)',
            'Tier 2 (day 10+)',
            'Tier 3 (day 20+)',
            'Variance detected',
            'View all →',
            'Waived',
            'Workspace',
            'available',
            'top requests',
            'vs loan exposure',
        ];
    }

    /**
     * @return list<string>
     */
    public static function untranslatedRequiredKeys(): array
    {
        /** @var array<string, string> $arabic */
        $arabic = self::arabicCatalog();

        $untranslated = [];

        foreach (self::requiredRedesignKeys() as $key) {
            if (! array_key_exists($key, $arabic)) {
                $untranslated[] = $key;

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
        return (bool) preg_match('/\\p{Arabic}/u', $value);
    }
}
