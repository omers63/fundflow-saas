<?php

declare(strict_types=1);

namespace Tests\Support;

final class HardcodedEnglishCatalog
{
    /**
     * Blade / PHP paths scanned for user-facing English not wrapped in translation helpers.
     *
     * @return list<string>
     */
    public static function scanPaths(): array
    {
        $root = dirname(__DIR__, 2);

        return [
            $root.'/app/Filament/Tenant',
            $root.'/app/Filament/Member',
            $root.'/resources/views/filament/tenant',
            $root.'/resources/views/filament/member',
        ];
    }

    /**
     * @return list<string>
     */
    public static function findings(): array
    {
        $script = dirname(__DIR__, 2).'/scripts/find-hardcoded-english.php';

        ob_start();
        include $script;
        $output = (string) ob_get_clean();

        $lines = array_values(array_filter(explode("\n", trim($output)), fn (string $line): bool => str_contains($line, ':')));

        return $lines;
    }

    /**
     * Physical left/right alignment classes break RTL table and form layouts.
     *
     * @return list<string>
     */
    public static function physicalAlignmentFindings(): array
    {
        return array_values(array_filter(
            self::findings(),
            fn (string $line): bool => str_contains($line, 'physical alignment class'),
        ));
    }
}
