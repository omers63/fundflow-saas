<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\User;

/**
 * Keep users.preferred_locale aligned with the active portal language switch (session/cookie).
 *
 * Filament uses the tenant guard, while language-switch package code historically read
 * auth()->user(). That left preferred_locale stuck (often ar) while the UI showed en,
 * so queued database/toast and template notifications rendered in the wrong language.
 */
final class UserPreferredLocale
{
    public static function syncFromRequestLocale(?string $locale = null): void
    {
        $locale ??= self::explicitSwitcherLocale();

        if (! is_string($locale) || ! AppLocale::isSupported($locale)) {
            return;
        }

        $user = TenantAuthUser::user();

        if ($user === null) {
            return;
        }

        self::persist($user, $locale);
    }

    public static function persist(User $user, string $locale): void
    {
        if (! AppLocale::isSupported($locale)) {
            return;
        }

        if ($user->preferred_locale === $locale) {
            return;
        }

        $user->forceFill(['preferred_locale' => $locale])->saveQuietly();
    }

    /**
     * Locale the user chose via the language switch (session or forever cookie), not app defaults.
     */
    public static function explicitSwitcherLocale(): ?string
    {
        $sessionLocale = session()->get('locale');

        if (is_string($sessionLocale) && AppLocale::isSupported($sessionLocale)) {
            return $sessionLocale;
        }

        $cookieLocale = request()->cookie('filament_language_switch_locale');

        if (is_string($cookieLocale) && AppLocale::isSupported($cookieLocale)) {
            return $cookieLocale;
        }

        return null;
    }
}
