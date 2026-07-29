<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;
use Carbon\Carbon;

final class BusinessDaySettings
{
    public const GROUP = 'general';

    public const KEY = 'business_day';

    public const KEY_BANNER_ADMIN = 'business_day_banner_admin';

    public const KEY_BANNER_MEMBER = 'business_day_banner_member';

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

    /**
     * @return array{business_day: ?string, business_day_banner_admin: bool, business_day_banner_member: bool}
     */
    public static function allForForm(): array
    {
        return [
            'business_day' => self::forForm(),
            'business_day_banner_admin' => self::showBannerOnAdmin(),
            'business_day_banner_member' => self::showBannerOnMember(),
        ];
    }

    public static function showBannerOnAdmin(): bool
    {
        return self::booleanSetting(self::KEY_BANNER_ADMIN, default: true);
    }

    public static function showBannerOnMember(): bool
    {
        return self::booleanSetting(self::KEY_BANNER_MEMBER, default: true);
    }

    /**
     * Whether the business-day footer banner should render for the given Filament panel.
     */
    public static function shouldShowFooterBanner(?string $panelId = null): bool
    {
        if (!self::isOverridden()) {
            return false;
        }

        $panelId ??= filament()->getCurrentPanel()?->getId();

        return match ($panelId) {
            'member' => self::showBannerOnMember(),
            'tenant' => self::showBannerOnAdmin(),
            default => false,
        };
    }

    /**
     * @param  array<string, mixed>|string|null  $state
     */
    public static function saveFromForm(mixed $state): void
    {
        if (is_array($state)) {
            $date = $state['business_day'] ?? null;
            self::saveDate($date);
            Setting::set(
                self::GROUP,
                self::KEY_BANNER_ADMIN,
                (bool) ($state['business_day_banner_admin'] ?? true) ? '1' : '0',
            );
            Setting::set(
                self::GROUP,
                self::KEY_BANNER_MEMBER,
                (bool) ($state['business_day_banner_member'] ?? true) ? '1' : '0',
            );

            return;
        }

        self::saveDate($state);
    }

    private static function saveDate(mixed $date): void
    {
        if (! filled($date)) {
            Setting::set(self::GROUP, self::KEY, null);

            return;
        }

        Setting::set(self::GROUP, self::KEY, Carbon::parse((string) $date)->toDateString());
    }

    private static function booleanSetting(string $key, bool $default): bool
    {
        $value = Setting::get(self::GROUP, $key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
