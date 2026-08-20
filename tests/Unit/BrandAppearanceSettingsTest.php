<?php

declare(strict_types=1);

use App\Models\Tenant\Setting;
use App\Support\AppBrand;
use App\Support\BrandAppearanceSettings;
use App\Support\WebPushNotification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->where('group', BrandAppearanceSettings::GROUP)->delete();
    Storage::fake('public');
});

it('uses bundled public files as default icon urls', function () {
    expect(BrandAppearanceSettings::faviconUrl())->toContain(AppBrand::iconWebPath('favicon_32'))
        ->and(BrandAppearanceSettings::appleTouchIconUrl())->toContain(AppBrand::iconWebPath('apple_touch'))
        ->and(BrandAppearanceSettings::iconUrl('pwa_72'))->toContain(AppBrand::iconWebPath('pwa_72'))
        ->and(BrandAppearanceSettings::iconUrl('pwa_192'))->toContain(AppBrand::iconWebPath('pwa_192'))
        ->and(BrandAppearanceSettings::iconUrl('pwa_512'))->toContain(AppBrand::iconWebPath('pwa_512'))
        ->and(BrandAppearanceSettings::notificationIconUrl())->toContain(AppBrand::iconWebPath('notification_icon'))
        ->and(BrandAppearanceSettings::notificationBadgeUrl())->toContain(AppBrand::iconWebPath('notification_badge'));
});

it('uses uploaded icon files when present on the public disk', function () {
    Storage::disk('public')->put('fund-branding/icons/pwa-192.png', 'icon');

    BrandAppearanceSettings::save([
        ...BrandAppearanceSettings::defaults(),
        'icon_pwa_192' => 'fund-branding/icons/pwa-192.png',
    ]);

    expect(BrandAppearanceSettings::iconUrl('pwa_192'))
        ->toContain('/tenancy/assets/fund-branding/icons/pwa-192.png');
});

it('falls back to the bundled icon when the uploaded file is missing', function () {
    BrandAppearanceSettings::save([
        ...BrandAppearanceSettings::defaults(),
        'icon_pwa_192' => 'fund-branding/icons/missing.png',
    ]);

    expect(BrandAppearanceSettings::iconUrl('pwa_192'))->toContain(AppBrand::iconWebPath('pwa_192'));
});

it('deletes the previous icon file when replaced', function () {
    Storage::disk('public')->put('fund-branding/icons/old.png', 'old');
    Storage::disk('public')->put('fund-branding/icons/new.png', 'new');

    BrandAppearanceSettings::save([
        ...BrandAppearanceSettings::defaults(),
        'icon_favicon_32' => 'fund-branding/icons/old.png',
    ]);

    BrandAppearanceSettings::save([
        ...BrandAppearanceSettings::defaults(),
        'icon_favicon_32' => 'fund-branding/icons/new.png',
    ]);

    Storage::disk('public')->assertMissing('fund-branding/icons/old.png');
    Storage::disk('public')->assertExists('fund-branding/icons/new.png');
});

it('normalizes theme hex colors and keeps current defaults', function () {
    expect(BrandAppearanceSettings::themeColor())->toBe('#059669')
        ->and(BrandAppearanceSettings::backgroundColor())->toBe('#059669')
        ->and(BrandAppearanceSettings::tenantPanelPrimary())->toBe('#0EA5E9')
        ->and(BrandAppearanceSettings::memberPanelPrimary())->toBe('#534AB7');

    BrandAppearanceSettings::save([
        ...BrandAppearanceSettings::defaults(),
        'theme_color' => '0d9488',
        'background_color' => '#111827',
    ]);

    expect(BrandAppearanceSettings::themeColor())->toBe('#0D9488')
        ->and(BrandAppearanceSettings::backgroundColor())->toBe('#111827')
        ->and(BrandAppearanceSettings::themeTokens()['rgb'])->toBe('13, 148, 136');

    BrandAppearanceSettings::save([
        ...BrandAppearanceSettings::defaults(),
        'theme_color' => '#0d8',
    ]);

    expect(BrandAppearanceSettings::themeColor())->toBe('#00DD88');
});

it('feeds web push icon urls from branding settings', function () {
    Storage::disk('public')->put('fund-branding/icons/push.png', 'icon');
    Storage::disk('public')->put('fund-branding/icons/badge.png', 'badge');

    BrandAppearanceSettings::save([
        ...BrandAppearanceSettings::defaults(),
        'icon_notification_icon' => 'fund-branding/icons/push.png',
        'icon_notification_badge' => 'fund-branding/icons/badge.png',
    ]);

    expect(WebPushNotification::iconUrl())->toContain('/tenancy/assets/fund-branding/icons/push.png')
        ->and(WebPushNotification::badgeUrl())->toContain('/tenancy/assets/fund-branding/icons/badge.png');
});

it('builds per-size pwa manifest icons from the current defaults', function () {
    $icons = BrandAppearanceSettings::pwaManifestIcons();

    expect($icons)->toHaveCount(8)
        ->and($icons[0]['src'])->toContain(AppBrand::iconWebPath('pwa_72'))
        ->and($icons[0]['sizes'])->toBe('72x72')
        ->and($icons[5]['purpose'])->toBe('any maskable')
        ->and($icons[7]['sizes'])->toBe('512x512')
        ->and($icons[7]['purpose'])->toBe('any maskable');
});

it('does not treat a stored bundled editor copy as a custom install icon', function () {
    $path = BrandAppearanceSettings::bundledIconPath('pwa_512');
    Storage::disk('public')->put($path, 'stale-fundflow-icon');

    Setting::set(BrandAppearanceSettings::GROUP, BrandAppearanceSettings::iconKey('pwa_512'), $path);

    expect(BrandAppearanceSettings::iconUrl('pwa_512'))->toContain(AppBrand::iconWebPath('pwa_512'));
});

it('prefills form uploads with bundled copies so the image editor has a file', function () {
    $form = BrandAppearanceSettings::allForForm();
    $path = BrandAppearanceSettings::bundledIconPath('pwa_192');

    expect($form['icon_pwa_192'])->toBe([$path]);
    Storage::disk('public')->assertExists($path);

    BrandAppearanceSettings::saveFromForm($form);

    expect(BrandAppearanceSettings::iconUrl('pwa_192'))->toContain(AppBrand::iconWebPath('pwa_192'));
});

it('does not treat an edited copy of a bundled icon as the bundled default', function () {
    $edited = 'fund-branding/icons/bundled/pwa_192-v1.png';
    Storage::disk('public')->put($edited, 'edited');

    expect(BrandAppearanceSettings::isBundledEditablePath($edited))->toBeFalse()
        ->and(BrandAppearanceSettings::isBundledEditablePath(BrandAppearanceSettings::bundledIconPath('pwa_192')))->toBeTrue();
});
