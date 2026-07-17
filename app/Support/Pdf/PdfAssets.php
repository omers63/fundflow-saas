<?php

declare(strict_types=1);

namespace App\Support\Pdf;

use App\Support\FundflowBrand;
use App\Support\PublicPageSettings;
use App\Support\TenantAssetUrl;
use Illuminate\Support\Facades\Storage;

final class PdfAssets
{
    /** @var array<string, string> */
    private static array $sarSymbolDataUris = [];

    public static function sarSymbolDataUri(string $fill = '#334155'): string
    {
        $fill = strtolower(trim($fill));

        if (isset(self::$sarSymbolDataUris[$fill])) {
            return self::$sarSymbolDataUris[$fill];
        }

        // Cropped viewBox so the glyph sits optically centered in DomPDF image boxes.
        $path = resource_path('pdf/assets/sar-symbol-pdf.svg');

        if (! is_file($path)) {
            $path = resource_path('pdf/assets/sar-symbol.svg');
        }

        $svg = (string) file_get_contents($path);
        $svg = preg_replace('/fill="#[^"]+"/i', 'fill="'.e($fill).'"', $svg) ?? $svg;

        return self::$sarSymbolDataUris[$fill] = 'data:image/svg+xml;base64,'.base64_encode($svg);
    }

    /**
     * Inline SVG for web UI (currentColor — works on mobile without U+20C1 font support).
     */
    public static function sarSymbolInlineMarkup(string $class = 'ff-sar-symbol__svg'): string
    {
        $path = resource_path('pdf/assets/sar-symbol.svg');
        $svg = (string) file_get_contents($path);
        $svg = preg_replace('/fill="#[^"]+"/', 'fill="currentColor"', $svg) ?? $svg;

        return preg_replace(
            '/<svg\b/',
            '<svg class="'.e($class).'" focusable="false" aria-hidden="true"',
            $svg,
            1,
        ) ?? $svg;
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
