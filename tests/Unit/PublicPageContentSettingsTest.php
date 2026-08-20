<?php

declare(strict_types=1);

use App\Models\Tenant\Setting;
use App\Support\PublicPageContentSettings;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->where('group', PublicPageContentSettings::GROUP)->delete();
    app()->setLocale('en');
});

it('returns the current english public copy as defaults', function () {
    expect(PublicPageContentSettings::text('hero_badge'))->toBe('Trusted family fund management')
        ->and(PublicPageContentSettings::text('hero_title_accent'))->toBe('together')
        ->and(PublicPageContentSettings::text('footer_tagline'))->toContain('zero-interest')
        ->and(PublicPageContentSettings::text('nav_home'))->toBe('Home')
        ->and(PublicPageContentSettings::enabled('nav_show_features'))->toBeTrue()
        ->and(PublicPageContentSettings::html('sidebar_html'))->toBe('')
        ->and(PublicPageContentSettings::html('header_banner'))->toBe('')
        ->and(PublicPageContentSettings::features())->toHaveCount(9)
        ->and(PublicPageContentSettings::steps())->toHaveCount(4)
        ->and(PublicPageContentSettings::features()[0]['title'])->toBe('Membership Management');
});

it('returns the current arabic public copy when the locale is ar', function () {
    app()->setLocale('ar');

    expect(PublicPageContentSettings::text('hero_badge'))->toBe('إدارة صندوق عائلي موثوق')
        ->and(PublicPageContentSettings::text('nav_apply'))->toBe('تقديم طلب عضوية')
        ->and(PublicPageContentSettings::text('footer_copyright', [
            'year' => '2026',
            'fund' => 'صندوق النور',
        ]))->toBe('2026 © صندوق النور. جميع الحقوق محفوظة.')
        ->and(PublicPageContentSettings::features()[2]['title'])->toBe('قروض بأسعار مناسبة')
        ->and(PublicPageContentSettings::steps()[0]['title'])->toBe('تقديم');
});

it('persists customized public chrome copy', function () {
    PublicPageContentSettings::saveFromForm([
        ...PublicPageContentSettings::allForForm(),
        'hero_badge_en' => 'Custom fund',
        'hero_badge_ar' => 'صندوق مخصص',
        'nav_show_features' => false,
        'sidebar_html_en' => '<p>Hours</p>',
        'landing_features' => [
            [
                'title_en' => 'One card',
                'title_ar' => 'بطاقة',
                'body_en' => 'Body',
                'body_ar' => 'النص',
            ],
        ],
    ]);

    app()->setLocale('en');

    expect(PublicPageContentSettings::text('hero_badge'))->toBe('Custom fund')
        ->and(PublicPageContentSettings::enabled('nav_show_features'))->toBeFalse()
        ->and(PublicPageContentSettings::html('sidebar_html'))->toBe('<p>Hours</p>')
        ->and(PublicPageContentSettings::features())->toHaveCount(1)
        ->and(PublicPageContentSettings::features()[0]['title'])->toBe('One card');

    app()->setLocale('ar');

    expect(PublicPageContentSettings::text('hero_badge'))->toBe('صندوق مخصص');
});
