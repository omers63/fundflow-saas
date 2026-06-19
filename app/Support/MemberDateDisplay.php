<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

final class MemberDateDisplay
{
    /**
     * Member-facing date: localized month/day names, Western digits (0–9) only.
     */
    public static function format(?CarbonInterface $date, string $pattern = 'M Y'): ?string
    {
        if ($date === null) {
            return null;
        }

        $formatted = $date->copy()
            ->locale(app()->getLocale())
            ->translatedFormat($pattern);

        return self::westernizeDigits($formatted);
    }

    public static function westernizeDigits(string $value): string
    {
        static $from = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        static $to = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($from, $to, $value);
    }
}
