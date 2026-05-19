<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

final class StatementSettings
{
    public const GROUP = 'statement';

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
            'include_transactions' => true,
            'include_loan_section' => true,
            'include_compliance' => false,
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
            'statement_include_transactions' => (bool) ($all['include_transactions'] ?? true),
            'statement_include_loan_section' => (bool) ($all['include_loan_section'] ?? true),
            'statement_include_compliance' => (bool) ($all['include_compliance'] ?? false),
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
        Setting::set(self::GROUP, 'include_transactions', (bool) ($state['statement_include_transactions'] ?? true));
        Setting::set(self::GROUP, 'include_loan_section', (bool) ($state['statement_include_loan_section'] ?? true));
        Setting::set(self::GROUP, 'include_compliance', (bool) ($state['statement_include_compliance'] ?? false));
    }

    private static function get(string $key, mixed $default): mixed
    {
        $value = Setting::get(self::GROUP, $key);

        return $value !== null ? $value : $default;
    }
}
