<?php

use App\Support\Lang;
use Tests\TestCase;

uses(TestCase::class);

it('translates string values in option arrays while preserving keys', function (): void {
    expect(Lang::transOptions([
        'a' => 'Alpha',
        'b' => 'Beta',
    ]))->toBe([
        'a' => 'Alpha',
        'b' => 'Beta',
    ]);
});
