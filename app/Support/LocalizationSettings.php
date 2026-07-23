<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;
use Illuminate\Http\Request;

final class LocalizationSettings
{
    public const GROUP = 'localization';

    /**
     * @return array<string, string>
     */
    public static function localeOptions(): array
    {
        return [
            'ar' => __('Arabic'),
            'en' => __('English'),
        ];
    }

    public static function adminLocale(): string
    {
        return self::resolveLocaleKey('default_admin_locale');
    }

    public static function memberLocale(): string
    {
        return self::resolveLocaleKey('default_member_locale');
    }

    public static function guestLocale(?Request $request = null): string
    {
        $request ??= request();
        $path = trim((string) $request->path(), '/');

        if ($path === 'admin' || str_starts_with($path, 'admin/')) {
            return self::adminLocale();
        }

        return self::memberLocale();
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'default_admin_locale' => 'en',
            'default_member_locale' => 'ar',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function allForForm(): array
    {
        return [
            'localization_default_admin_locale' => self::adminLocale(),
            'localization_default_member_locale' => self::memberLocale(),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function saveFromForm(array $state): void
    {
        Setting::set(
            self::GROUP,
            'default_admin_locale',
            self::sanitizeLocale($state['localization_default_admin_locale'] ?? null),
        );
        Setting::set(
            self::GROUP,
            'default_member_locale',
            self::sanitizeLocale($state['localization_default_member_locale'] ?? null),
        );
    }

    private static function resolveLocaleKey(string $key): string
    {
        if (! tenancy()->initialized) {
            return self::sanitizeLocale(self::defaults()[$key] ?? AppLocale::DEFAULT);
        }

        $value = Setting::get(self::GROUP, $key);

        if ($value === null || $value === '') {
            return self::sanitizeLocale(self::defaults()[$key] ?? AppLocale::DEFAULT);
        }

        return self::sanitizeLocale($value);
    }

    private static function sanitizeLocale(mixed $locale): string
    {
        $locale = (string) ($locale ?? AppLocale::DEFAULT);

        return AppLocale::isSupported($locale) ? $locale : AppLocale::DEFAULT;
    }
}
