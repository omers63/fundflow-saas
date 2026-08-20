<?php

declare(strict_types=1);

use App\Support\AppBrand;
use App\Support\BrandAppearanceSettings;
use App\Support\CommunicationBrandSettings;
use App\Support\PublicPageContentSettings;

beforeEach(function () {
    config([
        'branding.path' => base_path('branding'),
        'branding.active' => 'fundflow',
        'branding.default' => 'fundflow',
    ]);
    AppBrand::flush();
});

afterEach(function () {
    AppBrand::flush();
});

it('uses the fundflow pack as the default application brand', function () {
    expect(AppBrand::slug())->toBe('fundflow')
        ->and(AppBrand::available())->toContain('fundflow')
        ->and(AppBrand::available())->toContain('samman')
        ->and(AppBrand::available())->toContain('samman-unity')
        ->and(AppBrand::available())->toContain('samman-seal')
        ->and(AppBrand::name())->toBe('FundFlow')
        ->and(is_file(AppBrand::logoAbsolutePath()))->toBeTrue()
        ->and(is_file(AppBrand::iconAbsolutePath('pwa_192')))->toBeTrue()
        ->and(is_file(AppBrand::iconAbsolutePath('notification_icon')))->toBeTrue()
        ->and(is_file(AppBrand::iconAbsolutePath('notification_badge')))->toBeTrue()
        ->and(AppBrand::logoWebPath())->toBe('/branding/fundflow/logo.png')
        ->and(AppBrand::iconWebPath('favicon_32'))->toBe('/branding/fundflow/icons/favicon-32x32.png')
        ->and(AppBrand::hasWordmark())->toBeFalse()
        ->and(AppBrand::hasSplash())->toBeFalse()
        ->and(AppBrand::shortName())->toBe('FundFlow')
        ->and(AppBrand::webManifest()['icons'][7]['src'])->toBe('/branding/fundflow/icons/icon-512x512.png');
});

it('loads the current theme and public page copy from the fundflow pack', function () {
    expect(AppBrand::themeColor())->toBe(BrandAppearanceSettings::DEFAULT_THEME_COLOR)
        ->and(AppBrand::backgroundColor())->toBe(BrandAppearanceSettings::DEFAULT_BACKGROUND_COLOR)
        ->and(AppBrand::tenantPanelPrimary())->toBe(BrandAppearanceSettings::DEFAULT_TENANT_PANEL_PRIMARY)
        ->and(AppBrand::memberPanelPrimary())->toBe(BrandAppearanceSettings::DEFAULT_MEMBER_PANEL_PRIMARY)
        ->and(CommunicationBrandSettings::defaults()['primary_color'])->toBe(AppBrand::communicationPrimary())
        ->and(AppBrand::publicContent()['hero_badge_en'])->toBe('Trusted family fund management')
        ->and(PublicPageContentSettings::defaultFeatures())->toHaveCount(9)
        ->and(PublicPageContentSettings::defaultSteps())->toHaveCount(4);
});

it('falls back to the default pack when APP_BRAND is unknown', function () {
    config(['branding.active' => 'does-not-exist']);
    AppBrand::flush();

    expect(AppBrand::slug())->toBe('fundflow')
        ->and(AppBrand::logoWebPath())->toBe('/branding/fundflow/logo.png');
});

it('ships a complete Samman concept pack with app and notification icons', function (string $slug, string $theme, string $hero, array $marks) {
    expect(AppBrand::exists($slug))->toBeTrue();

    config(['branding.active' => $slug]);
    AppBrand::flush();

    expect(AppBrand::slug())->toBe($slug)
        ->and(AppBrand::name())->toBe('Sheikh Sulaiman Samman Family Fund')
        ->and(AppBrand::themeColor())->toBe($theme)
        ->and(is_file(AppBrand::logoAbsolutePath()))->toBeTrue()
        ->and(is_file(AppBrand::absolutePath('logo.svg')))->toBeTrue()
        ->and(is_file(AppBrand::absolutePath('favicon.svg')))->toBeTrue()
        ->and(AppBrand::publicContent()['hero_badge_en'])->toBe($hero)
        ->and(AppBrand::publicContent()['features'])->toHaveCount(9);

    foreach (array_keys(BrandAppearanceSettings::ICON_SLOTS) as $slot) {
        expect(is_file(AppBrand::iconAbsolutePath($slot)))->toBeTrue();
    }

    foreach ($marks as $mark) {
        expect(is_file(AppBrand::absolutePath('marks/'.$mark.'.png')))->toBeTrue();
    }

    expect(AppBrand::hasWordmark())->toBeTrue()
        ->and(AppBrand::hasSplash())->toBeTrue()
        ->and(AppBrand::wordmarkUrl())->toEndWith('/branding/'.$slug.'/marks/wordmark.png')
        ->and(AppBrand::splashStartupImages())->toHaveCount(count(AppBrand::SPLASH_STARTUP_IMAGES))
        ->and(AppBrand::splashStartupImages()[0]['url'])->toContain('/branding/'.$slug.'/splash/1290x2796.png')
        ->and(AppBrand::splashStartupImages()[0]['media'])->toContain('device-width: 430px')
        ->and(AppBrand::webManifest()['short_name'])->toBe('Samman Fund')
        ->and(AppBrand::webManifest()['icons'][7]['src'])->toBe('/branding/'.$slug.'/icons/icon-512x512.png');
})->with([
    'concept 1' => ['samman', '#1E392E', 'Heritage, family, and continuity', ['heritage', 'family', 'identity', 'sustainability']],
    'concept 2' => ['samman-unity', '#0E7C7B', 'Unity, growth, and trust', ['unity', 'growth', 'support', 'trust']],
    'concept 3' => ['samman-seal', '#1A2A1F', 'Balance, authenticity, and continuity', ['diamond', 'leaves', 'calligraphy', 'frame']],
]);

it('switches icon and theme defaults when APP_BRAND points at another pack', function () {
    $root = sys_get_temp_dir().'/ff-brands-'.uniqid();
    $pack = $root.'/altbrand';
    mkdir($pack.'/icons', 0755, true);

    file_put_contents($pack.'/brand.json', json_encode([
        'name' => 'Alt Brand',
        'assets' => [
            'logo' => 'logo.png',
            'icons' => [
                'favicon_32' => 'icons/favicon-32x32.png',
                'pwa_192' => 'icons/icon-192x192.png',
            ],
        ],
        'communication' => [
            'footer_en' => 'Sent by alt brand.',
        ],
    ], JSON_UNESCAPED_SLASHES));
    file_put_contents($pack.'/theme.json', json_encode([
        'theme_color' => '#111827',
        'background_color' => '#F8FAFC',
        'tenant_panel_primary' => '#DC2626',
        'member_panel_primary' => '#7C3AED',
        'communication_primary' => '#BE185D',
    ]));
    file_put_contents($pack.'/logo.png', 'logo');
    file_put_contents($pack.'/icons/favicon-32x32.png', 'favicon');
    file_put_contents($pack.'/icons/icon-192x192.png', 'icon');

    config([
        'branding.path' => $root,
        'branding.active' => 'altbrand',
        'branding.default' => 'altbrand',
    ]);
    AppBrand::flush();

    expect(AppBrand::slug())->toBe('altbrand')
        ->and(AppBrand::name())->toBe('Alt Brand')
        ->and(AppBrand::themeColor())->toBe('#111827')
        ->and(AppBrand::iconWebPath('pwa_192'))->toBe('/branding/altbrand/icons/icon-192x192.png')
        ->and(AppBrand::communication()['footer_en'])->toBe('Sent by alt brand.')
        ->and(BrandAppearanceSettings::defaults()['theme_color'])->toBe('#111827');

    unlink($pack.'/icons/favicon-32x32.png');
    unlink($pack.'/icons/icon-192x192.png');
    unlink($pack.'/logo.png');
    unlink($pack.'/brand.json');
    unlink($pack.'/theme.json');
    rmdir($pack.'/icons');
    rmdir($pack);
    rmdir($root);
});
