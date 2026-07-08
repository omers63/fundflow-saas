<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonInterface;

/**
 * Formats timestamps for display while optionally shifting the calendar date
 * to the configured business day (Settings → Business calendar).
 */
final class BusinessDayDisplay
{
    public static function formatDateTime(?CarbonInterface $date, string $fallbackPattern = 'M j, Y g:i A'): ?string
    {
        if ($date === null) {
            return null;
        }

        $value = $date->copy();

        if (BusinessDay::isOverridden()) {
            $businessDay = BusinessDay::today();
            $value = $value->setDate(
                $businessDay->year,
                $businessDay->month,
                $businessDay->day,
            );
        }

        return $value->format($fallbackPattern);
    }
}
