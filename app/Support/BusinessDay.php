<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use Carbon\CarbonInterface;

final class BusinessDay
{
    public static function resolvedNow(): Carbon
    {
        $date = BusinessDaySettings::date();

        if ($date === null) {
            return Carbon::now();
        }

        $clock = Carbon::createFromTimestamp(time());

        return $date->copy()->setTime(
            $clock->hour,
            $clock->minute,
            $clock->second,
            $clock->micro,
        );
    }

    /**
     * @deprecated No-op. Business day is resolved on demand; never mutates the global clock.
     */
    public static function apply(): void {}

    /**
     * @deprecated No-op. Business day is resolved on demand; never mutates the global clock.
     */
    public static function reset(): void {}

    public static function today(): Carbon
    {
        return self::resolvedNow()->copy()->startOfDay();
    }

    public static function now(): Carbon
    {
        return self::resolvedNow();
    }

    public static function calendarToday(): Carbon
    {
        return Carbon::parse(date('Y-m-d'))->startOfDay();
    }

    public static function isOverridden(): bool
    {
        return BusinessDaySettings::isOverridden();
    }

    /**
     * Y-m-d key for placing a collection timestamp on the calendar grid.
     *
     * Collections posted through the app use {@see now()} and already carry the
     * configured business day on the date component.
     */
    public static function collectionDateKey(CarbonInterface|string|null $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Carbon::parse($value)->startOfDay()->toDateString();
    }
}
