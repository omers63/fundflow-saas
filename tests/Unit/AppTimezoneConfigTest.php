<?php

use Tests\TestCase;

uses(TestCase::class);

test('application timezone is read from APP_TIMEZONE', function (): void {
    expect(config('app.timezone'))->toBe('America/Toronto');
});
