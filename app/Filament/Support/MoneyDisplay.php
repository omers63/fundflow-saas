<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Setting;
use App\Support\Pdf\PdfAssets;
use Illuminate\Support\HtmlString;
use Illuminate\Support\Number;

final class MoneyDisplay
{
    private const LTR_ISOLATE_START = "\u{2066}";

    private const POP_DIRECTIONAL_ISOLATE = "\u{2069}";

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
        if (self::usesSvgSymbol($currency)) {
            return 'ff-sar-symbol ff-sar-symbol--svg';
        }

        return str_contains(self::symbol($currency), "\u{20C1}")
            ? 'ff-sar-symbol ff-sar-symbol--glyph'
            : 'ff-sar-symbol ff-sar-symbol--code';
    }

    /**
     * Arabic SAR amounts use an inline SVG — mobile OS fonts do not yet include U+20C1.
     */
    public static function usesSvgSymbol(?string $currency = null): bool
    {
        if (app()->getLocale() !== 'ar') {
            return false;
        }

        $currencyCode = $currency ?? Setting::get('general', 'currency', 'USD');

        return $currencyCode === 'SAR' || str_contains(self::symbol($currency), "\u{20C1}");
    }

    /**
     * @deprecated Use {@see usesSvgSymbol()} — kept for PDF-specific call sites.
     */
    public static function usesPdfSvgSymbol(?string $currency = null): bool
    {
        return self::usesSvgSymbol($currency);
    }

    public static function symbolHtml(?string $currency = null): HtmlString
    {
        return new HtmlString(self::symbolSpanHtml($currency));
    }

    /**
     * Marked-up currency symbol for HTML contexts (SVG img in Arabic, text otherwise).
     */
    public static function symbolMarkup(?string $currency = null): string
    {
        if (self::usesSvgSymbol($currency)) {
            return self::sarSymbolImageMarkup();
        }

        return e(self::symbol($currency));
    }

    /**
     * Compact amount with localized currency symbol before digits (e.g. ⃁ 1.2M).
     */
    public static function compactWithSymbol(float $amount, ?string $currency = null): string
    {
        $parts = self::compactParts($amount);

        return self::isolateLtrRun(self::symbol($currency).' '.$parts['digits']);
    }

    /**
     * Marked-up compact amount for UI (symbol before digits, LTR isolated).
     */
    public static function compactHtml(float $amount, ?string $currency = null): HtmlString
    {
        $parts = self::compactParts($amount);
        $precision = $parts['precision'];

        if ($precision !== null) {
            return self::html($amount, $currency, precision: $precision)
                ?? new HtmlString('—');
        }

        $colorClass = $amount < 0 ? ' ff-member-amount--danger' : '';

        return new HtmlString(
            '<span class="ff-member-amount tabular-nums'.$colorClass.'" dir="ltr">'
            .self::symbolSpanHtml($currency)
            .'<span class="ff-member-amount__digits">'.$parts['digits'].'</span>'
            .'</span>'
        );
    }

    /**
     * @return array{amount: float, digits: string, precision: ?int}
     */
    private static function compactParts(float $amount): array
    {
        $abs = abs($amount);

        if ($abs >= 1_000_000) {
            return [
                'amount' => $abs,
                'digits' => Number::format($abs / 1_000_000, 1, locale: 'en').'M',
                'precision' => null,
            ];
        }

        if ($abs >= 1_000) {
            return [
                'amount' => $abs,
                'digits' => Number::format($abs / 1_000, 1, locale: 'en').'K',
                'precision' => null,
            ];
        }

        return [
            'amount' => $abs,
            'digits' => self::amount($abs, 0) ?? '0',
            'precision' => 0,
        ];
    }

    /**
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

        return self::isolateLtrRun(self::symbol($currency).' '.$digits);
    }

    /**
     * Wrap plain-text money for embedding in RTL copy (symbol stays before digits).
     */
    public static function isolateLtrRun(?string $text): ?string
    {
        if ($text === null || $text === '' || app()->getLocale() !== 'ar') {
            return $text;
        }

        return self::LTR_ISOLATE_START.$text.self::POP_DIRECTIONAL_ISOLATE;
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

        $colorClass = match (true) {
            ((float) $amount) < 0 => ' ff-member-amount--danger',
            $signed => ' ff-member-amount--'.self::color($amount),
            default => '',
        };

        return new HtmlString(
            '<span class="ff-member-amount tabular-nums'.$colorClass.'" dir="ltr">'
            .self::symbolSpanHtml($currency)
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
        bool $signed = false,
    ): ?HtmlString {
        $digits = self::amount($amount, $precision);

        if ($digits === null) {
            return null;
        }

        if ($signed) {
            $value = (float) $amount;
            $digits = ($value > 0 ? '+' : ($value < 0 ? '−' : '')) . $digits;
        }

        $currencyCode = $currency ?? Setting::get('general', 'currency', 'USD');
        $symbol = self::symbol($currencyCode);

        if ($currencyCode === 'SAR' || str_contains($symbol, "\u{20C1}")) {
            $symbolMarkup = self::usesSvgSymbol($currencyCode)
                ? self::sarSymbolImageMarkup('currency-symbol', width: 12, height: 12)
                : '<span class="currency-code">SAR</span>';
        } else {
            $symbolMarkup = '<span class="currency-code">'.e($symbol).'</span>';
        }

        return new HtmlString(
            '<table class="amount" dir="ltr" border="0" cellpadding="0" cellspacing="0" style="border-collapse:collapse;border-spacing:0;direction:ltr;vertical-align:middle;">'
            . '<tr>'
            . '<td class="amount-symbol" style="border:0;padding:0 6px 0 0;margin:0;vertical-align:middle;line-height:0;background:transparent;">'
            . $symbolMarkup
            . '</td>'
            . '<td class="amount-digits" style="border:0;padding:0;margin:0;vertical-align:middle;font-weight:700;font-size:12px;line-height:12px;white-space:nowrap;background:transparent;">'
            . $digits
            . '</td>'
            . '</tr></table>'
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
            return self::html($value, $currency, signed: $signed || (float) $value < 0, precision: $precision)?->toHtml() ?? e('—');
        }

        $text = trim((string) $value);

        if ($text === '' || $text === '—') {
            return e($text === '' ? '—' : $text);
        }

        foreach (self::compoundSeparators($text) as $separator) {
            if (! str_contains($text, $separator)) {
                continue;
            }

            $parts = array_map(trim(...), explode($separator, $text));

            if (count($parts) < 2) {
                continue;
            }

            $rendered = array_map(
                fn (string $part): string => self::markupForDisplay($part, $currency, precision: $precision, signed: $signed),
                $parts,
            );

            return implode(e($separator), $rendered);
        }

        $parsed = self::parseFormatString($text, $currency);

        if ($parsed !== null) {
            if ($parsed['compact'] ?? false) {
                return self::compactHtml($parsed['amount'], $parsed['currency'] ?? $currency)->toHtml();
            }

            return self::html(
                $parsed['amount'],
                $parsed['currency'] ?? $currency,
                signed: $signed || $parsed['amount'] < 0,
                precision: $parsed['precision'],
            )?->toHtml() ?? e($text);
        }

        return e($text);
    }

    /**
     * @return list<string>
     */
    private static function compoundSeparators(string $text): array
    {
        $separators = [' / ', ' – ', ' · '];

        return array_values(array_filter(
            $separators,
            fn (string $separator): bool => str_contains($text, $separator),
        ));
    }

    /**
     * @return array{amount: float, precision: int, currency: ?string, compact?: bool}|null
     */
    public static function parseFormatString(string $text, ?string $currency = null): ?array
    {
        $text = trim(self::stripBidiIsolates($text));
        $symbols = array_unique(array_filter([
            preg_quote(self::symbol($currency), '/'),
            'SAR',
            preg_quote("\u{20C1}", '/'),
        ]));

        $symbolPattern = implode('|', $symbols);

        if (preg_match('/^('.$symbolPattern.')\s+([\d,]+(?:\.\d+)?)([KkMm])$/u', $text, $matches)) {
            $base = (float) str_replace(',', '', $matches[2]);
            $multiplier = strtolower($matches[3]) === 'm' ? 1_000_000 : 1_000;

            return [
                'amount' => $base * $multiplier,
                'precision' => 0,
                'currency' => self::matchedCurrency($matches[1]),
                'compact' => true,
            ];
        }

        if (! preg_match('/^('.$symbolPattern.')\s+([\d,]+(?:\.\d+)?)$/u', $text, $matches)) {
            return null;
        }

        $digits = str_replace(',', '', $matches[2]);
        $fraction = str_contains($matches[2], '.') ? explode('.', $matches[2])[1] : '';

        return [
            'amount' => (float) $digits,
            'precision' => strlen($fraction),
            'currency' => self::matchedCurrency($matches[1]),
        ];
    }

    private static function matchedCurrency(string $matchedSymbol): ?string
    {
        return ($matchedSymbol === 'SAR' || $matchedSymbol === "\u{20C1}") ? 'SAR' : null;
    }

    private static function stripBidiIsolates(string $text): string
    {
        return str_replace([self::LTR_ISOLATE_START, self::POP_DIRECTIONAL_ISOLATE], '', $text);
    }

    private static function symbolSpanHtml(?string $currency = null): string
    {
        return '<span class="'.self::symbolSpanClass($currency).'" dir="ltr">'
            .self::symbolMarkup($currency)
            .'</span>';
    }

    private static function sarSymbolImageMarkup(
        string $class = 'ff-sar-symbol__img',
        ?int $width = null,
        ?int $height = null,
    ): string {
        $sizeAttributes = '';

        if ($width !== null && $height !== null) {
            $sizeAttributes = ' width="'.$width.'" height="'.$height.'"';
        }

        return '<img src="'.PdfAssets::sarSymbolDataUri().'" alt="" class="'.e($class).'"'.$sizeAttributes.' decoding="async" />';
    }
}
