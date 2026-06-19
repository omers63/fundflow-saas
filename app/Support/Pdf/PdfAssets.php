<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use App\Support\FundflowBrand;
use App\Support\PublicPageSettings;
use App\Support\TenantAssetUrl;
use Illuminate\Support\Facades\Storage;

final class PdfAssets
{
    private static ?string $sarSymbolDataUri = null;

    public static function sarSymbolDataUri(): string
    {
        if (self::$sarSymbolDataUri !== null) {
            return self::$sarSymbolDataUri;
        }

        $path = resource_path('pdf/assets/sar-symbol.svg');

        return self::$sarSymbolDataUri = self::fileDataUri($path, 'image/svg+xml');
    }

    public static function fundLogoDataUri(): ?string
    {
        $path = PublicPageSettings::fundLogoPath();

        if ($path !== null && TenantAssetUrl::publicDiskExists($path)) {
            return self::fileDataUriIfSupported(Storage::disk('public')->path($path));
        }

        $default = public_path(FundflowBrand::LOGO_ASSET);

        if (is_file($default)) {
            return self::fileDataUriIfSupported($default);
        }

        return null;
    }

    private static function fileDataUriIfSupported(string $absolutePath): ?string
    {
        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        if (in_array($extension, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true) && ! extension_loaded('gd')) {
            return null;
        }

        return self::fileDataUri($absolutePath);
    }

    private static function fileDataUri(string $absolutePath, ?string $mime = null): string
    {
        $mime ??= match (strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            'jpg', 'jpeg' => 'image/jpeg',
            'gif' => 'image/gif',
            'webp' => 'image/webp',
            'svg' => 'image/svg+xml',
            default => 'application/octet-stream',
        };

        return 'data:'.$mime.';base64,'.base64_encode((string) file_get_contents($absolutePath));
    }
}
