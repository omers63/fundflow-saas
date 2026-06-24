<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

/**
 * Tenant setting for how legacy migration CSVs interpret ambiguous slash dates.
 */
final class LegacyMigrationDateFormatSettings
{
    public const GROUP = 'legacy_migration';

    public const KEY = 'date_formats';

    public const SLASH_US = 'm/d/Y';

    public const SLASH_EUROPEAN = 'd/m/Y';

    /**
     * @return array<string, string>
     */
    public static function slashDateFormatOptions(): array
    {
        return [
            self::SLASH_US => __('MM/DD/YYYY (US — e.g. 11/3/2025 = November 3)'),
            self::SLASH_EUROPEAN => __('DD/MM/YYYY (e.g. 03/11/2025 = 3 November)'),
        ];
    }

    public static function defaultSlashDateFormat(): string
    {
        return self::SLASH_US;
    }

    /**
     * @return list<string>
     */
    public static function defaultFormats(): array
    {
        return [
            'Y-m-d',
            self::defaultSlashDateFormat(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function formats(): array
    {
        $stored = Setting::get(self::GROUP, self::KEY);

        if ($stored === null || $stored === '') {
            return self::defaultFormats();
        }

        $normalized = ImportDateFormats::normalize($stored);

        return self::ensureIsoFirst($normalized);
    }

    public static function slashDateFormat(): string
    {
        foreach (self::formats() as $format) {
            if (in_array($format, [self::SLASH_US, self::SLASH_EUROPEAN], true)) {
                return $format;
            }
        }

        return self::defaultSlashDateFormat();
    }

    public static function saveSlashDateFormat(string $slashFormat): void
    {
        $slashFormat = in_array($slashFormat, [self::SLASH_US, self::SLASH_EUROPEAN], true)
            ? $slashFormat
            : self::defaultSlashDateFormat();

        self::saveFormats([
            'Y-m-d',
            $slashFormat,
        ]);
    }

    /**
     * @param  list<string>  $formats
     */
    public static function saveFormats(array $formats): void
    {
        $formats = self::ensureIsoFirst(ImportDateFormats::normalize($formats));

        $slashFormats = array_values(array_filter(
            $formats,
            fn (string $format): bool => in_array($format, [self::SLASH_US, self::SLASH_EUROPEAN], true),
        ));

        if (count($slashFormats) > 1) {
            throw new \InvalidArgumentException(
                ImportDateFormats::contradictionMessage($slashFormats)
                ?? __('Choose only one slash date format.'),
            );
        }

        Setting::set(self::GROUP, self::KEY, json_encode($formats, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<string>  $formats
     * @return list<string>
     */
    private static function ensureIsoFirst(array $formats): array
    {
        if ($formats === []) {
            return self::defaultFormats();
        }

        $withoutIso = array_values(array_filter($formats, fn (string $format): bool => $format !== 'Y-m-d'));

        return array_values(array_unique(array_merge(['Y-m-d'], $withoutIso)));
    }
}
