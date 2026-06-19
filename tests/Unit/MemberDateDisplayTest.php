<?php

declare(strict_types=1);

use App\Support\MemberDateDisplay;
use Carbon\Carbon;
use Tests\TestCase;

uses(TestCase::class);

it('formats member dates with western digits in arabic locale', function (): void {
    app()->setLocale('ar');

    $formatted = MemberDateDisplay::format(Carbon::parse('2018-03-15'), 'M Y');

    expect($formatted)
        ->not->toBeNull()
        ->not->toMatch('/[٠-٩]/u')
        ->toContain('2018');
});

it('westernizes arabic-indic digits', function (): void {
    expect(MemberDateDisplay::westernizeDigits('١٢٣٤'))->toBe('1234');
});

it('returns null for null dates', function (): void {
    expect(MemberDateDisplay::format(null))->toBeNull();
});
