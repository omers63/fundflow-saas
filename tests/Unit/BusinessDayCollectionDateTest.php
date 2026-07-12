<?php

declare(strict_types=1);

use App\Support\BusinessDay;
use Carbon\Carbon;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
});

test('collection date key returns start of day date string', function () {
    expect(BusinessDay::collectionDateKey(Carbon::parse('2026-05-10 14:30:00')))
        ->toBe('2026-05-10');
});

test('collection date key accepts null', function () {
    expect(BusinessDay::collectionDateKey(null))->toBeNull();
});
