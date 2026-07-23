<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

final class StatementSettings
{
    public const GROUP = 'statement';

    public const FONT_DEJAVU_SANS = 'dejavu_sans';

    public const FONT_DEJAVU_SERIF = 'dejavu_serif';

    public const FONT_DEJAVU_SANS_MONO = 'dejavu_sans_mono';

    public const FONT_AMIRI = 'amiri';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'brand_name' => config('app.name'),
            'tagline' => __('Member fund statement'),
            'accent_color' => '#059669',
            'footer_disclaimer' => __('Computer-generated statement. Confidential.'),
            'signature_line' => __('Fund administration'),
            'auto_email' => false,
            'attach_pdf' => false,
            'include_transactions' => true,
            'include_loan_section' => true,
            'include_compliance' => false,
            'font_en' => self::FONT_DEJAVU_SANS,
            'font_ar' => self::FONT_DEJAVU_SANS,
        ];
    }

    /**
     * DomPDF-safe English statement typefaces.
     *
     * @return array<string, string>
     */
    public static function englishFontOptions(): array
    {
        return [
            self::FONT_DEJAVU_SANS => __('DejaVu Sans (default)'),
            self::FONT_DEJAVU_SERIF => __('DejaVu Serif'),
            self::FONT_DEJAVU_SANS_MONO => __('DejaVu Sans Mono'),
        ];
    }

    /**
     * DomPDF-safe Arabic statement typefaces (Unicode + glyph shaping).
     *
     * @return array<string, string>
     */
    public static function arabicFontOptions(): array
    {
        return [
            self::FONT_DEJAVU_SANS => __('DejaVu Sans (default)'),
            self::FONT_DEJAVU_SERIF => __('DejaVu Serif'),
            self::FONT_AMIRI => __('Amiri (traditional Arabic)'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return array_merge(self::defaults(), Setting::getGroup(self::GROUP));
    }

    /**
     * @return array<string, mixed>
     */
    public static function allForForm(): array
    {
        $all = self::all();

        return [
            'statement_brand_name' => $all['brand_name'],
            'statement_tagline' => $all['tagline'],
            'statement_accent_color' => $all['accent_color'],
            'statement_footer_disclaimer' => $all['footer_disclaimer'],
            'statement_signature_line' => $all['signature_line'],
            'statement_auto_email' => (bool) ($all['auto_email'] ?? false),
            'statement_attach_pdf' => (bool) ($all['attach_pdf'] ?? false),
            'statement_include_transactions' => (bool) ($all['include_transactions'] ?? true),
            'statement_include_loan_section' => (bool) ($all['include_loan_section'] ?? true),
            'statement_include_compliance' => (bool) ($all['include_compliance'] ?? false),
            'statement_font_en' => self::englishFont(),
            'statement_font_ar' => self::arabicFont(),
        ];
    }

    public static function brandName(): string
    {
        return (string) self::get('brand_name', config('app.name'));
    }

    public static function tagline(): string
    {
        return (string) self::get('tagline', __('Member fund statement'));
    }

    public static function accentColor(): string
    {
        return (string) self::get('accent_color', '#059669');
    }

    public static function footerDisclaimer(): string
    {
        return (string) self::get('footer_disclaimer', __('Computer-generated statement. Confidential.'));
    }

    public static function signatureLine(): string
    {
        return (string) self::get('signature_line', __('Fund administration'));
    }

    public static function autoEmail(): bool
    {
        return (bool) self::get('auto_email', false);
    }

    public static function attachPdf(): bool
    {
        return (bool) self::get('attach_pdf', false);
    }

    public static function includeTransactions(): bool
    {
        return (bool) self::get('include_transactions', true);
    }

    public static function includeLoanSection(): bool
    {
        return (bool) self::get('include_loan_section', true);
    }

    public static function includeCompliance(): bool
    {
        return (bool) self::get('include_compliance', false);
    }

    public static function englishFont(): string
    {
        $font = (string) self::get('font_en', self::FONT_DEJAVU_SANS);

        return array_key_exists($font, self::englishFontOptions())
            ? $font
            : self::FONT_DEJAVU_SANS;
    }

    public static function arabicFont(): string
    {
        $font = (string) self::get('font_ar', self::FONT_DEJAVU_SANS);

        return array_key_exists($font, self::arabicFontOptions())
            ? $font
            : self::FONT_DEJAVU_SANS;
    }

    /**
     * CSS font-family for DomPDF body text for the given (or current) locale.
     */
    public static function pdfFontFamily(?string $locale = null): string
    {
        $locale ??= app()->getLocale();
        $key = $locale === 'ar' ? self::arabicFont() : self::englishFont();

        return self::fontFamilyCss($key);
    }

    public static function fontFamilyCss(string $fontKey): string
    {
        return match ($fontKey) {
            self::FONT_DEJAVU_SERIF => 'DejaVu Serif',
            self::FONT_DEJAVU_SANS_MONO => 'DejaVu Sans Mono',
            self::FONT_AMIRI => 'Amiri',
            default => 'DejaVu Sans',
        };
    }

    /**
     * Absolute path to a custom TTF when DomPDF must register it, otherwise null.
     */
    public static function customFontPath(string $fontKey): ?string
    {
        if ($fontKey !== self::FONT_AMIRI) {
            return null;
        }

        $path = resource_path('fonts/pdf/Amiri-Regular.ttf');

        return is_file($path) ? $path : null;
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function saveFromForm(array $state): void
    {
        Setting::set(self::GROUP, 'brand_name', trim((string) ($state['statement_brand_name'] ?? config('app.name'))));
        Setting::set(self::GROUP, 'tagline', trim((string) ($state['statement_tagline'] ?? '')));

        $color = trim((string) ($state['statement_accent_color'] ?? '#059669'));
        if (preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
            Setting::set(self::GROUP, 'accent_color', $color);
        }

        Setting::set(self::GROUP, 'footer_disclaimer', trim((string) ($state['statement_footer_disclaimer'] ?? '')));
        Setting::set(self::GROUP, 'signature_line', trim((string) ($state['statement_signature_line'] ?? '')));
        Setting::set(self::GROUP, 'auto_email', (bool) ($state['statement_auto_email'] ?? false));
        Setting::set(self::GROUP, 'attach_pdf', (bool) ($state['statement_attach_pdf'] ?? false));
        Setting::set(self::GROUP, 'include_transactions', (bool) ($state['statement_include_transactions'] ?? true));
        Setting::set(self::GROUP, 'include_loan_section', (bool) ($state['statement_include_loan_section'] ?? true));
        Setting::set(self::GROUP, 'include_compliance', (bool) ($state['statement_include_compliance'] ?? false));

        $fontEn = (string) ($state['statement_font_en'] ?? self::FONT_DEJAVU_SANS);
        Setting::set(
            self::GROUP,
            'font_en',
            array_key_exists($fontEn, self::englishFontOptions()) ? $fontEn : self::FONT_DEJAVU_SANS,
        );

        $fontAr = (string) ($state['statement_font_ar'] ?? self::FONT_DEJAVU_SANS);
        Setting::set(
            self::GROUP,
            'font_ar',
            array_key_exists($fontAr, self::arabicFontOptions()) ? $fontAr : self::FONT_DEJAVU_SANS,
        );
    }

    private static function get(string $key, mixed $default): mixed
    {
        $value = Setting::get(self::GROUP, $key);

        return $value !== null ? $value : $default;
    }
}
