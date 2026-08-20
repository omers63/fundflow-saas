<?php

declare(strict_types=1);

namespace App\Support;

final class AppBrand
{
    public const DEFAULT_SLUG = 'fundflow';

    /** @var array<string, mixed>|null */
    private static ?array $manifest = null;

    /** @var array<string, mixed>|null */
    private static ?array $theme = null;

    /** @var array<string, mixed>|null */
    private static ?array $content = null;

    private static ?string $slug = null;

    public static function flush(): void
    {
        self::$manifest = null;
        self::$theme = null;
        self::$content = null;
        self::$slug = null;
    }

    /**
     * @return list<string>
     */
    public static function available(): array
    {
        $root = self::root();

        if (! is_dir($root)) {
            return [];
        }

        $slugs = [];

        foreach (scandir($root) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            if (self::exists($entry)) {
                $slugs[] = $entry;
            }
        }

        sort($slugs);

        return $slugs;
    }

    public static function exists(string $slug): bool
    {
        $slug = self::sanitizeSlug($slug);

        return $slug !== '' && is_file(self::directoryFor($slug).DIRECTORY_SEPARATOR.'brand.json');
    }

    public static function slug(): string
    {
        if (self::$slug !== null) {
            return self::$slug;
        }

        $requested = self::sanitizeSlug((string) config('branding.active', self::DEFAULT_SLUG));

        if (self::exists($requested)) {
            return self::$slug = $requested;
        }

        $fallback = self::sanitizeSlug((string) config('branding.default', self::DEFAULT_SLUG));

        if (self::exists($fallback)) {
            return self::$slug = $fallback;
        }

        return self::$slug = self::DEFAULT_SLUG;
    }

    public static function name(): string
    {
        $name = trim((string) (self::manifest()['name'] ?? ''));

        return $name !== '' ? $name : 'FundFlow';
    }

    public static function directory(): string
    {
        return self::directoryFor(self::slug());
    }

    public static function webPrefix(): string
    {
        return '/branding/'.self::slug();
    }

    public static function webPath(string $relative): string
    {
        return self::webPrefix().'/'.ltrim(str_replace('\\', '/', $relative), '/');
    }

    public static function url(string $relative): string
    {
        return url(self::webPath($relative));
    }

    public static function absolutePath(string $relative): string
    {
        $relative = ltrim(str_replace('\\', '/', $relative), '/');

        if ($relative === '' || str_contains($relative, '..')) {
            return self::directory();
        }

        return self::directory().DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, $relative);
    }

    public static function logoRelative(): string
    {
        $logo = trim((string) (self::manifest()['assets']['logo'] ?? 'logo.png'));

        return $logo !== '' ? $logo : 'logo.png';
    }

    public static function logoWebPath(): string
    {
        return self::webPath(self::logoRelative());
    }

    public static function logoUrl(): string
    {
        return self::url(self::logoRelative());
    }

    public static function logoAbsolutePath(): string
    {
        $path = self::absolutePath(self::logoRelative());

        if (is_file($path)) {
            return $path;
        }

        $public = public_path(ltrim(self::logoWebPath(), '/'));

        if (is_file($public)) {
            return $public;
        }

        $legacy = public_path('favicon-192x192.png');

        return is_file($legacy) ? $legacy : $path;
    }

    public static function iconRelative(string $slot): string
    {
        $icons = self::manifest()['assets']['icons'] ?? [];

        if (! is_array($icons)) {
            return '';
        }

        return trim((string) ($icons[$slot] ?? ''));
    }

    public static function iconWebPath(string $slot): string
    {
        $relative = self::iconRelative($slot);

        return $relative !== '' ? self::webPath($relative) : self::logoWebPath();
    }

    public static function iconUrl(string $slot): string
    {
        return url(self::iconWebPath($slot));
    }

    public static function iconAbsolutePath(string $slot): string
    {
        $relative = self::iconRelative($slot);
        $path = $relative !== '' ? self::absolutePath($relative) : '';

        if ($path !== '' && is_file($path)) {
            return $path;
        }

        $public = public_path(ltrim(self::iconWebPath($slot), '/'));

        return is_file($public) ? $public : $path;
    }

    public static function absoluteFromWebPath(string $webPath): string
    {
        $path = '/'.ltrim(str_replace('\\', '/', $webPath), '/');
        $prefix = self::webPrefix().'/';

        if (str_starts_with($path, $prefix)) {
            $fromPack = self::absolutePath(substr($path, strlen($prefix)));

            if (is_file($fromPack)) {
                return $fromPack;
            }
        }

        $fromPublic = public_path(ltrim($webPath, '/'));

        return is_file($fromPublic) ? $fromPublic : '';
    }

    public static function wordmarkRelative(): string
    {
        $path = trim((string) (self::manifest()['assets']['wordmark'] ?? 'marks/wordmark.png'));

        return $path !== '' ? $path : 'marks/wordmark.png';
    }

    public static function hasWordmark(): bool
    {
        return is_file(self::absolutePath(self::wordmarkRelative()));
    }

    public static function wordmarkUrl(): string
    {
        return self::url(self::wordmarkRelative());
    }

    public static function splashBackgroundColor(): string
    {
        return BrandAppearanceSettings::normalizeHex(
            self::theme()['splash_background_color'] ?? self::backgroundColor(),
            self::backgroundColor(),
        );
    }

    /**
     * Portrait iOS startup-image sizes for home-screen launch.
     *
     * @var list<array{width: int, height: int, device_width: int, device_height: int, dpr: int}>
     */
    public const SPLASH_STARTUP_IMAGES = [
        ['width' => 1290, 'height' => 2796, 'device_width' => 430, 'device_height' => 932, 'dpr' => 3],
        ['width' => 1284, 'height' => 2778, 'device_width' => 428, 'device_height' => 926, 'dpr' => 3],
        ['width' => 1179, 'height' => 2556, 'device_width' => 393, 'device_height' => 852, 'dpr' => 3],
        ['width' => 1170, 'height' => 2532, 'device_width' => 390, 'device_height' => 844, 'dpr' => 3],
        ['width' => 1125, 'height' => 2436, 'device_width' => 375, 'device_height' => 812, 'dpr' => 3],
        ['width' => 1242, 'height' => 2688, 'device_width' => 414, 'device_height' => 896, 'dpr' => 3],
        ['width' => 828, 'height' => 1792, 'device_width' => 414, 'device_height' => 896, 'dpr' => 2],
        ['width' => 1242, 'height' => 2208, 'device_width' => 414, 'device_height' => 736, 'dpr' => 3],
        ['width' => 750, 'height' => 1334, 'device_width' => 375, 'device_height' => 667, 'dpr' => 2],
    ];

    public static function hasSplash(): bool
    {
        return self::splashStartupImages() !== [];
    }

    /**
     * @return list<array{media: string, url: string}>
     */
    public static function splashStartupImages(): array
    {
        $images = [];

        foreach (self::SPLASH_STARTUP_IMAGES as $spec) {
            $relative = sprintf('splash/%dx%d.png', $spec['width'], $spec['height']);

            if (! is_file(self::absolutePath($relative))) {
                continue;
            }

            $images[] = [
                'media' => sprintf(
                    'screen and (device-width: %dpx) and (device-height: %dpx) and (-webkit-device-pixel-ratio: %d) and (orientation: portrait)',
                    $spec['device_width'],
                    $spec['device_height'],
                    $spec['dpr'],
                ),
                'url' => self::url($relative),
            ];
        }

        return $images;
    }

    public static function shortName(): string
    {
        $short = trim((string) (self::manifest()['short_name'] ?? ''));

        return $short !== '' ? $short : self::name();
    }

    public static function pwaDescription(): string
    {
        $locale = app()->getLocale();
        $arabic = trim((string) (self::manifest()['description_ar'] ?? ''));
        $english = trim((string) (self::manifest()['description_en'] ?? ''));

        if (str_starts_with($locale, 'ar') && $arabic !== '') {
            return $arabic;
        }

        return $english !== '' ? $english : $arabic;
    }

    /**
     * @return list<array{src: string, sizes: string, type: string, purpose: string}>
     */
    public static function pwaManifestIcons(): array
    {
        $icons = [];

        foreach (BrandAppearanceSettings::ICON_SLOTS as $slot => $meta) {
            if (! ($meta['pwa'] ?? false)) {
                continue;
            }

            $src = self::iconWebPath($slot);
            $type = str_ends_with(strtolower($src), '.svg')
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
     * @return array<string, mixed>
     */
    public static function webManifest(): array
    {
        return [
            'name' => self::name(),
            'short_name' => self::shortName(),
            'description' => self::pwaDescription(),
            'start_url' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => self::backgroundColor(),
            'theme_color' => self::themeColor(),
            'categories' => ['finance', 'business'],
            'icons' => self::pwaManifestIcons(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function theme(): array
    {
        if (self::$theme !== null) {
            return self::$theme;
        }

        return self::$theme = self::loadJson('theme.json');
    }

    public static function themeColor(): string
    {
        return BrandAppearanceSettings::normalizeHex(
            self::theme()['theme_color'] ?? BrandAppearanceSettings::DEFAULT_THEME_COLOR,
            BrandAppearanceSettings::DEFAULT_THEME_COLOR,
        );
    }

    public static function backgroundColor(): string
    {
        return BrandAppearanceSettings::normalizeHex(
            self::theme()['background_color'] ?? BrandAppearanceSettings::DEFAULT_BACKGROUND_COLOR,
            BrandAppearanceSettings::DEFAULT_BACKGROUND_COLOR,
        );
    }

    public static function tenantPanelPrimary(): string
    {
        return BrandAppearanceSettings::normalizeHex(
            self::theme()['tenant_panel_primary'] ?? BrandAppearanceSettings::DEFAULT_TENANT_PANEL_PRIMARY,
            BrandAppearanceSettings::DEFAULT_TENANT_PANEL_PRIMARY,
        );
    }

    public static function memberPanelPrimary(): string
    {
        return BrandAppearanceSettings::normalizeHex(
            self::theme()['member_panel_primary'] ?? BrandAppearanceSettings::DEFAULT_MEMBER_PANEL_PRIMARY,
            BrandAppearanceSettings::DEFAULT_MEMBER_PANEL_PRIMARY,
        );
    }

    public static function communicationPrimary(): string
    {
        return BrandAppearanceSettings::normalizeHex(
            self::theme()['communication_primary'] ?? '#0F766E',
            '#0F766E',
        );
    }

    /**
     * @return array<string, mixed>
     */
    public static function communication(): array
    {
        $communication = self::manifest()['communication'] ?? [];

        return is_array($communication) ? $communication : [];
    }

    /**
     * @return array<string, mixed>
     */
    public static function publicContent(): array
    {
        if (self::$content !== null) {
            return self::$content;
        }

        return self::$content = self::loadJson('content.json');
    }

    /**
     * @return array<string, mixed>
     */
    public static function manifest(): array
    {
        if (self::$manifest !== null) {
            return self::$manifest;
        }

        return self::$manifest = self::loadJson('brand.json');
    }

    private static function root(): string
    {
        return rtrim((string) config('branding.path', base_path('branding')), DIRECTORY_SEPARATOR);
    }

    private static function directoryFor(string $slug): string
    {
        return self::root().DIRECTORY_SEPARATOR.$slug;
    }

    private static function sanitizeSlug(string $slug): string
    {
        $slug = strtolower(trim($slug));

        if ($slug === '' || ! preg_match('/^[a-z0-9][a-z0-9_-]*$/', $slug)) {
            return '';
        }

        return $slug;
    }

    /**
     * @return array<string, mixed>
     */
    private static function loadJson(string $filename): array
    {
        $path = self::directory().DIRECTORY_SEPARATOR.$filename;

        if (! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }
}
