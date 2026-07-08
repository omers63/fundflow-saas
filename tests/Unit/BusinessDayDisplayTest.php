<?php

declare(strict_types=1);

use App\Models\Tenant\Setting;
use App\Support\BusinessDayDisplay;
use Illuminate\Support\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('formatDateTime keeps original date when business day is not overridden', function () {
    Setting::set('general', 'business_day', null);

    $formatted = BusinessDayDisplay::formatDateTime(Carbon::parse('2026-05-10 14:30:00'));

    expect($formatted)->toBe('May 10, 2026 2:30 PM');
});

test('formatDateTime shifts date to business day when overridden', function () {
    Setting::set('general', 'business_day', '2026-06-15');

    $formatted = BusinessDayDisplay::formatDateTime(Carbon::parse('2026-05-10 14:30:00'));

    expect($formatted)->toBe('Jun 15, 2026 2:30 PM');
});
