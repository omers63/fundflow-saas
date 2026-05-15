<?php

namespace App\Support;

final class FundflowBrand
{
    /** Same assets as the legacy FundFlow app (sharp at panel sizes). */
    public const FAVICON_ASSET = 'favicon-32x32.png';

    public const LOGO_ASSET = 'favicon-192x192.png';

    public static function faviconUrl(): string
    {
        return url('/'.self::FAVICON_ASSET);
    }

    public static function logoUrl(): string
    {
        return url('/'.self::LOGO_ASSET);
    }

    public static function panelLogoUrl(): string
    {
        return self::logoUrl();
    }
}
