<?php

namespace App\Support;

final class FundflowBrand
{
    public static function faviconUrl(): string
    {
        return AppBrand::iconUrl('favicon_32');
    }

    public static function logoUrl(): string
    {
        return AppBrand::logoUrl();
    }

    public static function panelLogoUrl(): string
    {
        return self::logoUrl();
    }

    public static function logoAbsolutePath(): string
    {
        return AppBrand::logoAbsolutePath();
    }
}
