<?php

declare(strict_types=1);

use App\Models\Central\Tenant;
use App\Models\Tenant\Setting;
use App\Services\ContributionCycleService;
use App\Support\BusinessDay;
use App\Support\BusinessDaySettings;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Setting::query()->delete();
});

test('business day setting can be saved and cleared', function () {
    BusinessDaySettings::saveFromForm('2026-08-15');

    expect(BusinessDaySettings::forForm())->toBe('2026-08-15')
        ->and(BusinessDaySettings::isOverridden())->toBeTrue();

    BusinessDaySettings::saveFromForm(null);

    expect(BusinessDaySettings::forForm())->toBeNull()
        ->and(BusinessDaySettings::isOverridden())->toBeFalse();
});

test('business day override resolves now and today without mutating the global clock', function () {
    Setting::set('general', 'business_day', '2026-09-01');

    expect(BusinessDay::now()->toDateString())->toBe('2026-09-01')
        ->and(BusinessDay::today()->toDateString())->toBe('2026-09-01')
        ->and(now()->toDateString())->not->toBe('2026-09-01')
        ->and(BusinessDay::calendarToday()->toDateString())->not->toBe('2026-09-01')
        ->and(Carbon::getTestNow())->toBeNull();
});

test('contribution cycle uses configured business day as today', function () {
    Setting::set('general', 'business_day', '2026-07-20');
    Setting::set('contribution', 'cycle_start_day', 6);

    $cycles = app(ContributionCycleService::class);
    [$month, $year] = $cycles->currentOpenPeriod();

    expect($month)->toBe(7)
        ->and($year)->toBe(2026);
});

test('member login page keeps a valid session cookie when business day is overridden to the past', function () {
    Setting::set('general', 'business_day', '2024-01-15');

    $tenant = Tenant::find('testing');
    $domain = 'testing.localhost';

    if (! $tenant->domains()->where('domain', $domain)->exists()) {
        $tenant->domains()->create(['domain' => $domain]);
    }

    $response = $this->get('http://'.$domain.'/member/login');

    $response->assertSuccessful();

    $sessionCookie = collect($response->headers->getCookies())
        ->first(fn ($cookie) => $cookie->getName() === config('session.cookie'));

    expect($sessionCookie)->not->toBeNull()
        ->and($sessionCookie->getExpiresTime())->toBeGreaterThan(time());
});
