<?php

namespace App\Support;

use Carbon\Carbon;
use Throwable;

/**
 * Date format presets for CSV import templates (bank statements, etc.).
 */
class ImportDateFormats
{
    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            'Y-m-d' => 'YYYY-MM-DD (2026-01-15)',
            'd/m/Y' => 'DD/MM/YYYY (15/01/2026)',
            'm/d/Y' => 'MM/DD/YYYY (01/15/2026)',
            'd-m-Y' => 'DD-MM-YYYY (15-01-2026)',
            'd.m.Y' => 'DD.MM.YYYY (15.01.2026)',
        ];
    }

    /**
     * @param  array<int, string>|string|null  $value
     * @return array<int, string>
     */
    public static function normalize(array|string|null $value): array
    {
        if ($value === null || $value === '') {
            return ['Y-m-d'];
        }

        if (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed === '') {
                return ['Y-m-d'];
            }

            if (str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    return self::filterKnown($decoded);
                }
            }

            return self::filterKnown([$trimmed]);
        }

        return self::filterKnown($value);
    }

    /**
     * @param  array<int, string>  $formats
     */
    public static function contradictionMessage(array $formats): ?string
    {
        $formats = self::filterKnown($formats);

        if ($formats === []) {
            return __('Select at least one date format.');
        }

        $probes = [
            '01/02/2023',
            '02/01/2023',
            '15/06/2023',
            '06/15/2023',
            '2023-01-15',
            '15.01.2023',
            '15-01-2023',
            '01-15-2023',
        ];

        foreach ($probes as $probe) {
            $parsedDates = [];

            foreach ($formats as $format) {
                $parsed = self::tryParseStrict($probe, $format);

                if ($parsed !== null) {
                    $parsedDates[] = $parsed;
                }
            }

            if (count(array_unique($parsedDates)) > 1) {
                return __('The selected date formats can interpret the same value differently (for example :example). Remove conflicting formats or keep only one ordering (day/month vs month/day).', [
                    'example' => $probe,
                ]);
            }
        }

        return null;
    }

    /**
     * @param  array<int, string>|string|null  $formats
     */
    public static function parse(string $raw, array|string|null $formats): Carbon
    {
        $value = trim($raw);

        if ($value === '') {
            throw new \InvalidArgumentException(__('Date is empty.'));
        }

        foreach (self::normalize($formats) as $format) {
            $parsed = self::tryParseStrict($value, $format);

            if ($parsed !== null) {
                return Carbon::createFromFormat('!Y-m-d', $parsed);
            }
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            throw new \InvalidArgumentException(__('Could not parse date: :value', ['value' => $raw]));
        }
    }

    /**
     * @param  array<int, mixed>  $formats
     * @return array<int, string>
     */
    private static function filterKnown(array $formats): array
    {
        $known = array_keys(self::options());
        $filtered = [];

        foreach ($formats as $format) {
            if (! is_string($format)) {
                continue;
            }

            $format = trim($format);

            if ($format !== '' && in_array($format, $known, true) && ! in_array($format, $filtered, true)) {
                $filtered[] = $format;
            }
        }

        return $filtered;
    }

    private static function tryParseStrict(string $value, string $format): ?string
    {
        try {
            $date = Carbon::createFromFormat('!'.$format, $value);
        } catch (Throwable) {
            return null;
        }

        $errors = Carbon::getLastErrors();

        if (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0) {
            return null;
        }

        return $date->format('Y-m-d');
    }
}
