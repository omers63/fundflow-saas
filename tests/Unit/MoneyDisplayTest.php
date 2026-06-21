<?php

use App\Filament\Support\MoneyDisplay;
use Tests\TestCase;

uses(TestCase::class);

it('formats negative amounts without a minus sign', function (): void {
    app()->setLocale('en');

    expect(MoneyDisplay::format(-50000, 'SAR', 'en'))
        ->toBe('SAR 50,000.00');
});

it('formats positive amounts with currency symbol before the value in english', function (): void {
    app()->setLocale('en');

    expect(MoneyDisplay::format(1250.5, 'SAR', 'en'))
        ->toBe('SAR 1,250.50');
});

it('uses western digits and places currency symbol before amount in arabic locale', function (): void {
    app()->setLocale('ar');

    expect(MoneyDisplay::format(3240, 'SAR'))
        ->toBe("\u{2066}\u{20C1} 3,240.00\u{2069}")
        ->and(MoneyDisplay::amount(3240))->toBe('3,240.00');
});

it('wraps plain format strings in ltr isolate for rtl locale', function (): void {
    app()->setLocale('ar');

    expect(MoneyDisplay::isolateLtrRun('SAR 100'))
        ->toBe("\u{2066}SAR 100\u{2069}");

    app()->setLocale('en');

    expect(MoneyDisplay::isolateLtrRun('SAR 100'))->toBe('SAR 100');
});

it('uses translated currency symbol from lang files', function (): void {
    app()->setLocale('ar');

    expect(MoneyDisplay::symbol('SAR'))->toBe("\u{20C1}");
});

it('renders html markup with inline svg symbol before digits in arabic', function (): void {
    app()->setLocale('ar');

    $html = (string) MoneyDisplay::html(3240, 'SAR');

    expect($html)
        ->toContain('ff-sar-symbol--svg')
        ->toContain('ff-sar-symbol__img')
        ->toContain('ff-member-amount__digits')
        ->toContain('3,240.00')
        ->not->toContain("\u{20C1}");

    expect(mb_strpos($html, 'ff-sar-symbol__img'))->toBeLessThan(mb_strpos($html, '3,240.00'));
});

it('renders html markup with symbol before digits in english', function (): void {
    app()->setLocale('en');

    $html = (string) MoneyDisplay::html(3240, 'SAR');

    expect(mb_strpos($html, 'SAR'))->toBeLessThan(mb_strpos($html, '3,240.00'));
});

it('uses code styling class for english sar label', function (): void {
    app()->setLocale('en');

    expect(MoneyDisplay::symbolSpanClass('SAR'))->toBe('ff-sar-symbol ff-sar-symbol--code');
});

it('uses svg styling class for arabic riyal sign in html', function (): void {
    app()->setLocale('ar');

    expect(MoneyDisplay::symbolSpanClass('SAR'))->toBe('ff-sar-symbol ff-sar-symbol--svg')
        ->and(MoneyDisplay::usesSvgSymbol('SAR'))->toBeTrue();
});

it('returns danger color for negative amounts and success for zero or positive', function (): void {
    expect(MoneyDisplay::color(-1))->toBe('danger')
        ->and(MoneyDisplay::color(0))->toBe('success')
        ->and(MoneyDisplay::color(100))->toBe('success');
});

it('supports custom precision for whole-number amounts', function (): void {
    app()->setLocale('en');

    expect(MoneyDisplay::format(500, 'SAR', precision: 0))->toBe('SAR 500');
});

it('renders table summary html with currency symbol markup', function (): void {
    app()->setLocale('ar');

    $html = MoneyDisplay::tableSummaryHtml(1500, 'SAR');

    expect($html)
        ->toContain('ff-sar-symbol--svg')
        ->toContain('ff-sar-symbol__img')
        ->toContain('1,500.00');
});

it('renders markup for plain format strings with symbol before digits in arabic', function (): void {
    app()->setLocale('ar');

    $html = MoneyDisplay::markupForDisplay(MoneyDisplay::format(1500, 'SAR'));

    expect($html)
        ->toContain('ff-member-amount')
        ->toContain('ff-sar-symbol__img')
        ->toContain('1,500.00');

    expect(mb_strpos($html, 'ff-sar-symbol__img'))->toBeLessThan(mb_strpos($html, '1,500.00'));
});

it('renders numeric amounts via markup helper', function (): void {
    app()->setLocale('ar');

    $html = MoneyDisplay::markupForDisplay(2500, 'SAR');

    expect(mb_strpos($html, 'ff-sar-symbol__img'))->toBeLessThan(mb_strpos($html, '2,500.00'));
});

it('escapes non-money stat values', function (): void {
    expect(MoneyDisplay::markupForDisplay('Under loan repayment'))
        ->toBe('Under loan repayment');
});

it('parses whole-number format strings', function (): void {
    app()->setLocale('en');

    expect(MoneyDisplay::parseFormatString('SAR 500'))
        ->toBe(['amount' => 500.0, 'precision' => 0, 'currency' => 'SAR']);
});

it('renders pdf amounts with embedded sar glyph image in arabic', function (): void {
    app()->setLocale('ar');

    $html = MoneyDisplay::pdfHtml(1500, 'SAR')?->toHtml();

    expect($html)
        ->toContain('currency-symbol')
        ->toContain('data:image/svg+xml;base64,')
        ->toContain('1,500.00');
});

it('formats compact amounts with symbol before digits in arabic', function (): void {
    app()->setLocale('ar');

    expect(MoneyDisplay::compactWithSymbol(1_500_000, 'SAR'))
        ->toBe("\u{2066}\u{20C1} 1.5M\u{2069}");
});

it('formats compact amounts with sar code in english', function (): void {
    app()->setLocale('en');

    expect(MoneyDisplay::compactWithSymbol(2500, 'SAR'))
        ->toBe('SAR 2.5K');
});

it('renders compact html with svg symbol before digits in arabic', function (): void {
    app()->setLocale('ar');

    $html = MoneyDisplay::compactHtml(1_500_000, 'SAR')->toHtml();

    expect($html)
        ->toContain('ff-member-amount')
        ->toContain('ff-sar-symbol__img')
        ->toContain('1.5M');

    expect(mb_strpos($html, 'ff-sar-symbol__img'))->toBeLessThan(mb_strpos($html, '1.5M'));
});

it('parses compact format strings for markup display', function (): void {
    app()->setLocale('ar');

    $html = MoneyDisplay::markupForDisplay(MoneyDisplay::compactWithSymbol(2500, 'SAR'));

    expect($html)->toContain('ff-member-amount')->toContain('2.5K');
});

it('renders compound money strings with symbol before each amount', function (): void {
    app()->setLocale('ar');

    $left = MoneyDisplay::compactWithSymbol(5000, 'SAR');
    $right = MoneyDisplay::compactWithSymbol(12000, 'SAR');
    $html = MoneyDisplay::markupForDisplay($left.' / '.$right);

    expect($html)
        ->toContain('ff-member-amount')
        ->toContain(' / ')
        ->toContain('5.0K')
        ->toContain('12.0K');
});

it('renders symbol html with inline svg in arabic', function (): void {
    app()->setLocale('ar');

    expect((string) MoneyDisplay::symbolHtml('SAR'))
        ->toContain('ff-sar-symbol--svg')
        ->toContain('ff-sar-symbol__img')
        ->not->toContain("\u{20C1}");
});

it('does not use svg symbol markup in english locale', function (): void {
    app()->setLocale('en');

    expect(MoneyDisplay::usesSvgSymbol('SAR'))->toBeFalse()
        ->and((string) MoneyDisplay::symbolHtml('SAR'))->toContain('SAR');
});

it('renders pdf amounts with sar code in english', function (): void {
    app()->setLocale('en');

    $html = MoneyDisplay::pdfHtml(1500, 'SAR')?->toHtml();

    expect($html)
        ->toContain('currency-code')
        ->toContain('SAR')
        ->toContain('1,500.00')
        ->not->toContain('currency-symbol');
});
