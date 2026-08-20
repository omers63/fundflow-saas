<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class IconPixelEditor
{
    public const MAX_BYTES = 2_000_000;

    /**
     * @return list<string>
     */
    public static function allowedFields(): array
    {
        $fields = ['fund_logo'];

        foreach (array_keys(BrandAppearanceSettings::ICON_SLOTS) as $slot) {
            $fields[] = BrandAppearanceSettings::iconKey($slot);
        }

        return $fields;
    }

    /**
     * @return array{0: int, 1: int}
     */
    public static function dimensionsForField(string $field): array
    {
        if ($field === 'fund_logo') {
            return [192, 192];
        }

        $slot = str_starts_with($field, 'icon_') ? substr($field, 5) : '';
        $sizes = BrandAppearanceSettings::ICON_SLOTS[$slot]['sizes'] ?? '1x1';
        $parts = explode('x', strtolower($sizes));

        return [
            max(1, min(1024, (int) ($parts[0] ?? 1))),
            max(1, min(1024, (int) ($parts[1] ?? $parts[0] ?? 1))),
        ];
    }

    public static function store(string $field, string $dataUrl): string
    {
        if (! in_array($field, self::allowedFields(), true)) {
            throw new InvalidArgumentException('Invalid icon field.');
        }

        $binary = self::decodePngDataUrl($dataUrl);
        [$width, $height] = self::dimensionsForField($field);
        $png = self::normalizePng($binary, $width, $height);
        $directory = $field === 'fund_logo' ? 'fund-branding' : 'fund-branding/icons';
        $path = $directory.'/drawn-'.$field.'-'.Str::ulid().'.png';

        Storage::disk('public')->put($path, $png);

        return $path;
    }

    public static function isDrawnPath(string $path): bool
    {
        return str_contains($path, '/drawn-');
    }

    private static function decodePngDataUrl(string $dataUrl): string
    {
        $dataUrl = trim($dataUrl);

        if (! str_starts_with($dataUrl, 'data:image/png;base64,')) {
            throw new InvalidArgumentException('Drawing must be a PNG.');
        }

        $binary = base64_decode(substr($dataUrl, strlen('data:image/png;base64,')), true);

        if ($binary === false || $binary === '' || strlen($binary) > self::MAX_BYTES) {
            throw new InvalidArgumentException('Drawing is empty or too large.');
        }

        return $binary;
    }

    private static function normalizePng(string $binary, int $width, int $height): string
    {
        $source = @imagecreatefromstring($binary);

        if ($source === false) {
            throw new InvalidArgumentException('Drawing could not be read.');
        }

        $dest = imagecreatetruecolor($width, $height);

        if ($dest === false) {
            imagedestroy($source);

            throw new InvalidArgumentException('Drawing could not be created.');
        }

        imagealphablending($dest, false);
        imagesavealpha($dest, true);
        $transparent = imagecolorallocatealpha($dest, 0, 0, 0, 127);
        imagefilledrectangle($dest, 0, 0, $width, $height, $transparent);
        imagealphablending($dest, true);
        imagecopyresized($dest, $source, 0, 0, 0, 0, $width, $height, imagesx($source), imagesy($source));
        imagedestroy($source);

        ob_start();
        imagesavealpha($dest, true);
        imagepng($dest);
        $png = (string) ob_get_clean();
        imagedestroy($dest);

        if ($png === '') {
            throw new InvalidArgumentException('Drawing could not be encoded.');
        }

        return $png;
    }
}
