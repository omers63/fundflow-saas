<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;
use Filament\Support\Colors\Color;
use Illuminate\Support\Facades\Storage;

final class BrandAppearanceSettings
{
    public const GROUP = 'branding';

    public const DEFAULT_THEME_COLOR = '#059669';

    public const DEFAULT_BACKGROUND_COLOR = '#059669';

    public const DEFAULT_TENANT_PANEL_PRIMARY = '#0EA5E9';

    public const DEFAULT_MEMBER_PANEL_PRIMARY = '#534AB7';

    public const BUNDLED_LOGO_PATH = 'fund-branding/bundled/logo.png';

    /**
     * Configurable image slots. Empty stored values resolve to the active brand pack.
     *
     * @var array<string, array{sizes: string, pwa?: bool, maskable?: bool}>
     */
    public const ICON_SLOTS = [
        'favicon_16' => [
            'sizes' => '16x16',
        ],
        'favicon_32' => [
            'sizes' => '32x32',
        ],
        'apple_touch' => [
            'sizes' => '180x180',
        ],
        'pwa_72' => [
            'sizes' => '72x72',
            'pwa' => true,
        ],
        'pwa_96' => [
            'sizes' => '96x96',
            'pwa' => true,
        ],
        'pwa_128' => [
            'sizes' => '128x128',
            'pwa' => true,
        ],
        'pwa_144' => [
            'sizes' => '144x144',
            'pwa' => true,
        ],
        'pwa_152' => [
            'sizes' => '152x152',
            'pwa' => true,
        ],
        'pwa_192' => [
            'sizes' => '192x192',
            'pwa' => true,
            'maskable' => true,
        ],
        'pwa_384' => [
            'sizes' => '384x384',
            'pwa' => true,
        ],
        'pwa_512' => [
            'sizes' => '512x512',
            'pwa' => true,
            'maskable' => true,
        ],
        'notification_icon' => [
            'sizes' => '192x192',
        ],
        'notification_badge' => [
            'sizes' => '96x96',
        ],
    ];

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        $defaults = [
            'theme_color' => AppBrand::themeColor(),
            'background_color' => AppBrand::backgroundColor(),
            'tenant_panel_primary' => AppBrand::tenantPanelPrimary(),
            'member_panel_primary' => AppBrand::memberPanelPrimary(),
        ];

        foreach (array_keys(self::ICON_SLOTS) as $slot) {
            $defaults[self::iconKey($slot)] = '';
        }

        return $defaults;
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return array_merge(self::defaults(), Setting::getGroup(self::GROUP));
    }

    /**
     * @return array<string, mixed>
     */
    public static function allForForm(): array
    {
        $all = self::all();
        $form = [
            'theme_color' => $all['theme_color'],
            'background_color' => $all['background_color'],
            'tenant_panel_primary' => $all['tenant_panel_primary'],
            'member_panel_primary' => $all['member_panel_primary'],
        ];

        foreach (array_keys(self::ICON_SLOTS) as $slot) {
            $key = self::iconKey($slot);
            $path = self::ensureEditableFile(
                $all[$key] ?? '',
                AppBrand::iconWebPath($slot),
                self::bundledIconPath($slot),
            );
            $form[$key] = $path !== '' ? [$path] : [];
        }

        return $form;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function saveFromForm(array $state): void
    {
        self::save([
            'theme_color' => $state['theme_color'] ?? AppBrand::themeColor(),
            'background_color' => $state['background_color'] ?? AppBrand::backgroundColor(),
            'tenant_panel_primary' => $state['tenant_panel_primary'] ?? AppBrand::tenantPanelPrimary(),
            'member_panel_primary' => $state['member_panel_primary'] ?? AppBrand::memberPanelPrimary(),
            ...self::iconValuesFromForm($state),
        ]);
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): void
    {
        foreach (['theme_color', 'background_color', 'tenant_panel_primary', 'member_panel_primary'] as $key) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            Setting::set(self::GROUP, $key, self::normalizeHex($values[$key], (string) self::defaults()[$key]));
        }

        foreach (array_keys(self::ICON_SLOTS) as $slot) {
            $key = self::iconKey($slot);

            if (! array_key_exists($key, $values)) {
                continue;
            }

            self::persistUpload($key, $values[$key]);
        }
    }

    public static function themeColor(): string
    {
        return self::normalizeHex(self::get('theme_color'), AppBrand::themeColor());
    }

    public static function backgroundColor(): string
    {
        return self::normalizeHex(self::get('background_color'), AppBrand::backgroundColor());
    }

    public static function tenantPanelPrimary(): string
    {
        return self::normalizeHex(self::get('tenant_panel_primary'), AppBrand::tenantPanelPrimary());
    }

    public static function memberPanelPrimary(): string
    {
        return self::normalizeHex(self::get('member_panel_primary'), AppBrand::memberPanelPrimary());
    }

    /**
     * @return array<int, string>
     */
    public static function tenantPanelColor(): array
    {
        if (tenant() === null) {
            return Color::Sky;
        }

        $hex = self::tenantPanelPrimary();

        if ($hex === self::DEFAULT_TENANT_PANEL_PRIMARY) {
            return Color::Sky;
        }

        return Color::hex($hex);
    }

    /**
     * @return array<int, string>
     */
    public static function memberPanelColor(): array
    {
        if (tenant() === null) {
            return Color::hex(AppBrand::memberPanelPrimary());
        }

        return Color::hex(self::memberPanelPrimary());
    }

    public static function iconUrl(string $slot): string
    {
        $meta = self::ICON_SLOTS[$slot] ?? null;

        if ($meta === null) {
            return FundflowBrand::logoUrl();
        }

        $stored = self::storedIconPath($slot);

        if (
            $stored !== null
            && ! self::isBundledEditablePath($stored)
            && TenantAssetUrl::publicDiskExists($stored)
        ) {
            return TenantAssetUrl::publicDisk($stored);
        }

        return AppBrand::iconUrl($slot);
    }

    public static function faviconUrl(): string
    {
        return self::iconUrl('favicon_32');
    }

    public static function appleTouchIconUrl(): string
    {
        return self::iconUrl('apple_touch');
    }

    public static function notificationIconUrl(): string
    {
        return self::iconUrl('notification_icon');
    }

    public static function notificationBadgeUrl(): string
    {
        return self::iconUrl('notification_badge');
    }

    /**
     * @return list<array{src: string, sizes: string, type: string, purpose: string}>
     */
    public static function pwaManifestIcons(): array
    {
        $icons = [];

        foreach (self::ICON_SLOTS as $slot => $meta) {
            if (! ($meta['pwa'] ?? false)) {
                continue;
            }

            $src = self::iconUrl($slot);
            $type = str_ends_with(strtolower(parse_url($src, PHP_URL_PATH) ?: $src), '.svg')
                ? 'image/svg+xml'
                : 'image/png';

            $icons[] = [
                'src' => $src,
                'sizes' => $meta['sizes'],
                'type' => $type,
                'purpose' => ($meta['maskable'] ?? false) ? 'any maskable' : 'any',
            ];
        }

        return $icons;
    }

    /**
     * @return array{theme: string, hover: string, rgb: string}
     */
    public static function themeTokens(): array
    {
        return self::themeTokensForHex(self::themeColor());
    }

    /**
     * @return array{theme: string, hover: string, rgb: string}
     */
    public static function themeTokensForHex(string $hex): array
    {
        $theme = self::normalizeHex($hex, AppBrand::themeColor());

        return [
            'theme' => $theme,
            'hover' => self::shadeHex($theme, 0.85),
            'rgb' => self::hexToRgb($theme),
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Setting::get(self::GROUP, $key);

        if ($value === null || $value === '') {
            return self::defaults()[$key] ?? $default;
        }

        return $value;
    }

    public static function iconKey(string $slot): string
    {
        return 'icon_'.$slot;
    }

    public static function bundledIconPath(string $slot): string
    {
        $relative = AppBrand::iconRelative($slot);
        $ext = pathinfo($relative !== '' ? $relative : 'png', PATHINFO_EXTENSION) ?: 'png';

        return 'fund-branding/icons/bundled/'.$slot.'.'.$ext;
    }

    /**
     * Copy a bundled public asset onto the tenant disk so FilePond can show the image editor.
     */
    public static function ensureEditableFile(?string $storedPath, string $defaultPublicPath, string $destination): string
    {
        $storedPath = ltrim(trim((string) $storedPath), '/');

        if (
            $storedPath !== ''
            && ! self::isBundledEditablePath($storedPath)
            && TenantAssetUrl::publicDiskExists($storedPath)
        ) {
            return $storedPath;
        }

        $destination = ltrim($destination, '/');
        $source = AppBrand::absoluteFromWebPath($defaultPublicPath);

        if ($source === '' || ! is_file($source)) {
            return TenantAssetUrl::publicDiskExists($destination) ? $destination : '';
        }

        Storage::disk('public')->put($destination, (string) file_get_contents($source));

        return $destination;
    }

    public static function isBundledEditablePath(string $path): bool
    {
        $path = ltrim($path, '/');

        if ($path === self::BUNDLED_LOGO_PATH) {
            return true;
        }

        foreach (array_keys(self::ICON_SLOTS) as $slot) {
            if ($path === self::bundledIconPath($slot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $state
     * @return array<string, mixed>
     */
    private static function iconValuesFromForm(array $state): array
    {
        $values = [];

        foreach (array_keys(self::ICON_SLOTS) as $slot) {
            $key = self::iconKey($slot);
            $values[$key] = $state[$key] ?? '';
        }

        return $values;
    }

    private static function storedIconPath(string $slot): ?string
    {
        $path = trim((string) Setting::get(self::GROUP, self::iconKey($slot), ''));

        return $path !== '' ? $path : null;
    }

    private static function persistUpload(string $key, mixed $value): void
    {
        $newPath = self::normalizeUploadPath($value);

        if (self::isBundledEditablePath($newPath)) {
            $newPath = '';
        }

        $previous = trim((string) Setting::get(self::GROUP, $key, ''));

        if (
            $previous !== ''
            && $previous !== $newPath
            && ! str_starts_with($previous, 'http')
            && ! self::isBundledEditablePath($previous)
        ) {
            Storage::disk('public')->delete($previous);
        }

        Setting::set(self::GROUP, $key, $newPath);
    }

    private static function normalizeUploadPath(mixed $value): string
    {
        if (is_array($value)) {
            $value = $value[array_key_first($value)] ?? '';
        }

        return is_string($value) ? trim($value) : '';
    }

    public static function normalizeHex(mixed $value, string $fallback): string
    {
        $value = strtoupper(trim((string) $value));

        if (preg_match('/^#([0-9A-F]{3})$/', $value) === 1) {
            return sprintf('#%s%s%s%s%s%s', $value[1], $value[1], $value[2], $value[2], $value[3], $value[3]);
        }

        if (preg_match('/^#([0-9A-F]{6})$/', $value) === 1) {
            return $value;
        }

        if (preg_match('/^([0-9A-F]{6})$/', $value) === 1) {
            return '#'.$value;
        }

        return strtoupper($fallback);
    }

    public static function hexToRgb(string $hex): string
    {
        $hex = ltrim($hex, '#');

        return implode(', ', [
            (string) hexdec(substr($hex, 0, 2)),
            (string) hexdec(substr($hex, 2, 2)),
            (string) hexdec(substr($hex, 4, 2)),
        ]);
    }

    public static function shadeHex(string $hex, float $factor): string
    {
        $hex = ltrim(self::normalizeHex($hex, self::DEFAULT_THEME_COLOR), '#');
        $factor = max(0.0, min(1.0, $factor));

        $channels = [
            (int) round(hexdec(substr($hex, 0, 2)) * $factor),
            (int) round(hexdec(substr($hex, 2, 2)) * $factor),
            (int) round(hexdec(substr($hex, 4, 2)) * $factor),
        ];

        return sprintf('#%02X%02X%02X', ...$channels);
    }
}
