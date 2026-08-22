<?php

declare(strict_types=1);

use App\Support\AppBrand;

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

it('omits startup images and the splash overlay for the FundFlow pack', function () {
    $head = view('partials.pwa-head')->render();
    $splash = view('partials.pwa-splash')->render();

    expect($head)->not->toContain('apple-touch-startup-image')
        ->and($splash)->not->toContain('ff-app-splash');
});

it('renders the icon-plus-wordmark splash for a Samman pack', function () {
    config(['branding.active' => 'samman']);
    AppBrand::flush();

    $head = view('partials.pwa-head')->render();
    $splash = view('partials.pwa-splash')->render();

    expect($head)->toContain('rel="apple-touch-startup-image"')
        ->and($head)->toContain('/branding/samman/splash/1290x2796.png')
        ->and($head)->toContain('device-width: 430px')
        ->and($head)->toContain('/branding/samman/icons/apple-touch-icon.png')
        ->and($splash)->toContain('id="ff-app-splash"')
        ->and($splash)->toContain('/branding/samman/icons/icon-512x512.png')
        ->and($splash)->toContain('/branding/samman/marks/wordmark.png')
        ->and($splash)->toContain('#F7F5ED')
        ->and($splash)->toContain('standalone ? 2000 : 2500');
});

it('serves the active brand pack icons from the web manifest', function () {
    $domain = config('tenancy.central_domain');

    $this->get('http://'.$domain.'/manifest.json')
        ->assertSuccessful()
        ->assertJsonPath('short_name', 'FundFlow')
        ->assertJsonPath('icons.7.src', '/branding/fundflow/icons/icon-512x512.png');

    config(['branding.active' => 'samman']);
    AppBrand::flush();

    $this->get('http://'.$domain.'/manifest.json')
        ->assertSuccessful()
        ->assertJsonPath('name', 'Sheikh Sulaiman Samman Family Fund')
        ->assertJsonPath('short_name', 'Samman Fund')
        ->assertJsonPath('theme_color', '#1E392E')
        ->assertJsonPath('icons.0.src', '/branding/samman/icons/icon-72x72.png')
        ->assertJsonPath('icons.7.src', '/branding/samman/icons/icon-512x512.png')
        ->assertJsonPath('icons.7.purpose', 'any maskable');
});
