<?php

declare(strict_types=1);

use App\Support\WaitingDuration;
use Carbon\Carbon;

beforeEach(function () {
    app()->setLocale('en');
});

test('waiting duration formats days only under one month', function () {
    $until = Carbon::parse('2025-07-13 12:00:00');
    $since = Carbon::parse('2025-07-10');

    expect(WaitingDuration::format($since, $until))->toBe('3 days')
        ->and(WaitingDuration::days($since, $until))->toBe(3);
});

test('waiting duration formats months only when remainder is zero', function () {
    $until = Carbon::parse('2025-08-10 12:00:00');
    $since = Carbon::parse('2025-07-10');

    expect(WaitingDuration::format($since, $until))->toBe('1 month');
});

test('waiting duration formats months and days together', function () {
    $until = Carbon::parse('2025-08-20 12:00:00');
    $since = Carbon::parse('2025-07-10');

    expect(WaitingDuration::format($since, $until))->toBe('1 month, 10 days');
});

test('waiting duration returns dash when applied date is missing', function () {
    expect(WaitingDuration::format(null))->toBe('—')
        ->and(WaitingDuration::days(null))->toBe(0);
});
