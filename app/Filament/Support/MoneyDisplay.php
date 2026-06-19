<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Setting;
use App\Support\Pdf\PdfAssets;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;

final class MoneyDisplay
{
    /**
     * Western-digit numeric portion only (no currency symbol).
     */
    public static function amount(float|int|string|null $value, int $precision = 2): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        return Number::format(abs((float) $value), $precision, locale: 'en');
    }

    /**
     * Localized currency symbol or code (e.g. SAR / official riyal sign in Arabic).
     */
    public static function symbol(?string $currency = null): string
    {
        $currencyCode = $currency ?? Setting::get('general', 'currency', 'USD');

        return __($currencyCode);
    }

    public static function symbolSpanClass(?string $currency = null): string
    {
        return str_contains(self::symbol($currency), "\u{20C1}")
            ? 'ff-sar-symbol ff-sar-symbol--glyph'
            : 'ff-sar-symbol ff-sar-symbol--code';
    }

    /**
     * Format like "SAR 50,000.00" or "⃁ 50,000.00" — symbol always left of digits.
     * Sign is not shown — use {@see color()} for danger (negative) vs success (zero/positive).
     *
     * @param  string|null  $locale  Deprecated; digits always use Western numerals.
     */
    public static function format(
        float|int|string|null $amount,
        ?string $currency = null,
        ?string $locale = null,
        int $precision = 2,
    ): ?string {
        if ($amount === null || $amount === '') {
            return null;
        }

        $digits = self::amount($amount, $precision);

        if ($digits === null) {
            return null;
        }

        return self::symbol($currency).' '.$digits;
    }

    /**
     * Marked-up amount for member UI: symbol left of digits.
     */
    public static function html(
        float|int|string|null $amount,
        ?string $currency = null,
        bool $signed = false,
        int $precision = 2,
    ): ?HtmlString {
        $digits = self::amount($amount, $precision);

        if ($digits === null) {
            return null;
        }

        $colorClass = $signed
            ? ' ff-member-amount--'.self::color($amount)
            : '';

        return new HtmlString(
            '<span class="ff-member-amount tabular-nums'.$colorClass.'" dir="ltr">'
            .'<span class="'.self::symbolSpanClass($currency).'" dir="ltr">'.e(self::symbol($currency)).'</span>'
            .'<span class="ff-member-amount__digits">'.$digits.'</span>'
            .'</span>'
        );
    }

    public static function color(float|int|string|null $amount): string
    {
        return ((float) $amount) < 0 ? 'danger' : 'success';
    }

    /**
     * Marked-up amount for DomPDF: uses an embedded riyal glyph image when DejaVu cannot render U+20C1.
     */
    public static function pdfHtml(
        float|int|string|null $amount,
        ?string $currency = null,
        int $precision = 2,
    ): ?HtmlString {
        $digits = self::amount($amount, $precision);

        if ($digits === null) {
            return null;
        }

        $currencyCode = $currency ?? Setting::get('general', 'currency', 'USD');
        $symbol = self::symbol($currencyCode);

        if ($currencyCode === 'SAR' || str_contains($symbol, "\u{20C1}")) {
            $symbolMarkup = app()->getLocale() === 'ar'
                ? '<img src="'.PdfAssets::sarSymbolDataUri().'" alt="" class="currency-symbol" width="11" height="11" />'
                : '<span class="currency-code">SAR</span>';
        } else {
            $symbolMarkup = '<span class="currency-code">'.e($symbol).'</span>';
        }

        return new HtmlString(
            '<span class="amount" dir="ltr"><span class="amount-inner">'
            .$symbolMarkup.' <span class="amount-digits">'.$digits.'</span>'
            .'</span></span>'
        );
    }

    /**
     * Marked-up amount for Filament table footer summarizers (matches row cell styling).
     */
    public static function tableSummaryHtml(
        float|int|string|null $amount,
        ?string $currency = null,
        bool $signed = false,
        int $precision = 2,
    ): ?string {
        if ($amount === null || $amount === '') {
            return null;
        }

        return self::html((float) $amount, $currency, signed: $signed, precision: $precision)?->toHtml();
    }

    /**
     * Render a stat line / card value: numeric amounts and {@see format()} strings become
     * marked-up {@see html()} (symbol before digits in RTL); other text is escaped.
     */
    public static function markupForDisplay(
        float|int|string|null $value,
        ?string $currency = null,
        int $precision = 2,
        bool $signed = false,
    ): string {
        if ($value === null || $value === '') {
            return e('—');
        }

        if (is_int($value) || is_float($value)) {
            return self::html($value, $currency, signed: $signed, precision: $precision)?->toHtml() ?? e('—');
        }

        $text = trim((string) $value);

        if ($text === '' || $text === '—') {
            return e($text === '' ? '—' : $text);
        }

        $parsed = self::parseFormatString($text, $currency);

        if ($parsed !== null) {
            return self::html(
                $parsed['amount'],
                $parsed['currency'] ?? $currency,
                signed: $signed,
                precision: $parsed['precision'],
            )?->toHtml() ?? e($text);
        }

        return e($text);
    }

    /**
     * @return array{amount: float, precision: int, currency: ?string}|null
     */
    public static function parseFormatString(string $text, ?string $currency = null): ?array
    {
        $symbols = array_unique(array_filter([
            preg_quote(self::symbol($currency), '/'),
            'SAR',
            preg_quote("\u{20C1}", '/'),
        ]));

        $symbolPattern = implode('|', $symbols);

        if (! preg_match('/^('.$symbolPattern.')\s+([\d,]+(?:\.\d+)?)$/u', trim($text), $matches)) {
            return null;
        }

        $digits = str_replace(',', '', $matches[2]);
        $fraction = str_contains($matches[2], '.') ? explode('.', $matches[2])[1] : '';
        $matchedSymbol = $matches[1];

        return [
            'amount' => (float) $digits,
            'precision' => strlen($fraction),
            'currency' => ($matchedSymbol === 'SAR' || $matchedSymbol === "\u{20C1}") ? 'SAR' : null,
        ];
    }
}
