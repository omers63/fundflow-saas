<?php

declare(strict_types=1);

namespace App\Support;

final class AppLocale
{
    /** @var list<string> */
    public const SUPPORTED = ['ar', 'en'];

    public const DEFAULT = 'ar';

    public const FALLBACK = 'en';

    public static function isRtl(?string $locale = null): bool
    {
        return ($locale ?? app()->getLocale()) === 'ar';
    }

    public static function htmlDir(?string $locale = null): string
    {
        return self::isRtl($locale) ? 'rtl' : 'ltr';
    }

    public static function isSupported(string $locale): bool
    {
        return in_array($locale, self::SUPPORTED, true);
    }
}
