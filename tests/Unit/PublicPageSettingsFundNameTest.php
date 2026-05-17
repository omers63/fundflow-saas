<?php

declare(strict_types=1);

use App\Models\Tenant\Setting;
use App\Support\PublicPageSettings;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->delete();
});

it('returns arabic fund name when locale is ar', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fund_name_en' => 'Al Noor Fund',
        'fund_name_ar' => 'صندوق النور',
    ]);

    app()->setLocale('ar');

    expect(PublicPageSettings::fundName())->toBe('صندوق النور');
});

it('returns english fund name when locale is en', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fund_name_en' => 'Al Noor Fund',
        'fund_name_ar' => 'صندوق النور',
    ]);

    app()->setLocale('en');

    expect(PublicPageSettings::fundName())->toBe('Al Noor Fund');
});

it('falls back to the other locale when primary name is empty', function () {
    PublicPageSettings::save([
        ...PublicPageSettings::defaults(),
        'fund_name_en' => 'Legacy English Only',
        'fund_name_ar' => '',
    ]);

    app()->setLocale('ar');

    expect(PublicPageSettings::fundName())->toBe('Legacy English Only');
});

it('migrates legacy fund_name into english on read', function () {
    Setting::set('public', 'fund_name', 'Old Fund Name');

    expect(PublicPageSettings::all()['fund_name_en'])->toBe('Old Fund Name');
});
