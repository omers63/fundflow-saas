<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

final class ArabicDisplaySettings
{
    public const GROUP = 'public';

    public const FONT_NOTO_SANS = 'noto_sans';

    public const FONT_TAJAWAL = 'tajawal';

    public const FONT_NASKH = 'naskh';

    /**
     * @return array<string, string>
     */
    public static function fontOptions(): array
    {
        return [
            self::FONT_NOTO_SANS => __('Noto Sans Arabic (default)'),
            self::FONT_TAJAWAL => __('Tajawal (modern UI)'),
            self::FONT_NASKH => __('Amiri / Naskh (traditional)'),
        ];
    }

    public static function fontPreset(): string
    {
        $preset = (string) self::get('arabic_display_font', self::FONT_NOTO_SANS);

        return array_key_exists($preset, self::fontOptions())
          ? $preset
          : self::FONT_NOTO_SANS;
    }

    public static function enhancedNameStyle(): bool
    {
        return filter_var(self::get('arabic_enhanced_name_style', '0'), FILTER_VALIDATE_BOOLEAN);
    }

    /**
     * CSS font-family value for :root --ff-font-arabic.
     */
    public static function fontFamilyCss(): string
    {
        return match (self::fontPreset()) {
            self::FONT_TAJAWAL => "'Tajawal', 'Noto Sans Arabic', ui-sans-serif, system-ui, sans-serif",
            self::FONT_NASKH => "'Amiri', 'Noto Naskh Arabic', 'Traditional Arabic', 'Segoe UI', serif",
            default => "'Noto Sans Arabic', ui-sans-serif, system-ui, sans-serif",
        };
    }

    /**
     * fonts.bunny.net family= query fragment (Instrument Sans is always included).
     */
    public static function bunnyFontsFamilyParam(): string
    {
        $presetFamilies = match (self::fontPreset()) {
            self::FONT_TAJAWAL => 'tajawal:400,500,600,700',
            self::FONT_NASKH => 'amiri:400,400i,700,700i|noto-naskh-arabic:400,500,600,700',
            default => 'noto-sans-arabic:400,500,600,700',
        };

        return 'instrument-sans:400,500,600,700|'.$presetFamilies;
    }

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'arabic_display_font' => self::FONT_NOTO_SANS,
            'arabic_enhanced_name_style' => '0',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function allForForm(): array
    {
        $stored = Setting::getGroup(self::GROUP);

        return [
            'arabic_display_font' => $stored['arabic_display_font'] ?? self::FONT_NOTO_SANS,
            'arabic_enhanced_name_style' => filter_var(
                $stored['arabic_enhanced_name_style'] ?? false,
                FILTER_VALIDATE_BOOLEAN,
            ),
        ];
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = Setting::get(self::GROUP, $key);

        if ($value === null || $value === '') {
            return self::defaults()[$key] ?? $default;
        }

        return $value;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): void
    {
        if (array_key_exists('arabic_display_font', $values)) {
            $preset = (string) $values['arabic_display_font'];
            Setting::set(
                self::GROUP,
                'arabic_display_font',
                array_key_exists($preset, self::fontOptions()) ? $preset : self::FONT_NOTO_SANS,
            );
        }

        if (array_key_exists('arabic_enhanced_name_style', $values)) {
            Setting::set(
                self::GROUP,
                'arabic_enhanced_name_style',
                ($values['arabic_enhanced_name_style'] ?? false) ? '1' : '0',
            );
        }
    }
}
