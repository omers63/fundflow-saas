<?php

use App\Filament\Support\MoneyDisplay;
use Tests\TestCase;

uses(TestCase::class);

it('formats negative amounts with currency code before the signed value', function (): void {
    expect(MoneyDisplay::format(-50000, 'SAR', 'en'))
        ->toBe('SAR -50,000.00');
});

it('formats positive amounts with currency code before the value', function (): void {
    expect(MoneyDisplay::format(1250.5, 'SAR', 'en'))
        ->toBe('SAR 1,250.50');
});

it('returns danger color for negative amounts and success for zero or positive', function (): void {
    expect(MoneyDisplay::color(-1))->toBe('danger')
        ->and(MoneyDisplay::color(0))->toBe('success')
        ->and(MoneyDisplay::color(100))->toBe('success');
});
