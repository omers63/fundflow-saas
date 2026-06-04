<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;
use Carbon\Carbon;

final class BusinessDaySettings
{
    public const GROUP = 'general';

    public const KEY = 'business_day';

    public static function date(): ?Carbon
    {
        if (! tenancy()->initialized) {
            return null;
        }

        $value = Setting::get(self::GROUP, self::KEY);

        if (! is_string($value) || blank($value)) {
            return null;
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function isOverridden(): bool
    {
        return self::date() !== null;
    }

    public static function forForm(): ?string
    {
        $value = Setting::get(self::GROUP, self::KEY);

        return is_string($value) && filled($value) ? $value : null;
    }

    public static function saveFromForm(mixed $date): void
    {
        if (! filled($date)) {
            Setting::set(self::GROUP, self::KEY, null);

            return;
        }

        Setting::set(self::GROUP, self::KEY, Carbon::parse((string) $date)->toDateString());
    }
}
