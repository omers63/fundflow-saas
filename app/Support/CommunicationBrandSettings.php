<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

final class CommunicationBrandSettings
{
    public const GROUP = 'communication_brand';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'from_name' => null,
            'primary_color' => '#0f766e',
            'footer_en' => 'This message was sent by your fund administration.',
            'footer_ar' => 'أُرسلت هذه الرسالة من إدارة الصندوق.',
            'logo_path' => null,
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
            'brand_from_name' => $all['from_name'],
            'brand_primary_color' => $all['primary_color'],
            'brand_footer_en' => $all['footer_en'],
            'brand_footer_ar' => $all['footer_ar'],
            'brand_logo_path' => $all['logo_path'],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function saveFromForm(array $state): void
    {
        Setting::set(self::GROUP, 'from_name', filled($state['brand_from_name'] ?? null) ? (string) $state['brand_from_name'] : null);
        Setting::set(self::GROUP, 'primary_color', (string) ($state['brand_primary_color'] ?? '#0f766e'));
        Setting::set(self::GROUP, 'footer_en', (string) ($state['brand_footer_en'] ?? self::defaults()['footer_en']));
        Setting::set(self::GROUP, 'footer_ar', (string) ($state['brand_footer_ar'] ?? self::defaults()['footer_ar']));
        Setting::set(self::GROUP, 'logo_path', filled($state['brand_logo_path'] ?? null) ? (string) $state['brand_logo_path'] : null);
    }

    public static function primaryColor(): string
    {
        return (string) (self::all()['primary_color'] ?? '#0f766e');
    }

    public static function footerForLocale(string $locale): string
    {
        $all = self::all();

        return $locale === 'ar'
            ? (string) ($all['footer_ar'] ?? self::defaults()['footer_ar'])
            : (string) ($all['footer_en'] ?? self::defaults()['footer_en']);
    }

    public static function fromName(): ?string
    {
        $value = self::all()['from_name'] ?? null;

        return filled($value) ? (string) $value : null;
    }

    public static function logoPath(): ?string
    {
        $value = self::all()['logo_path'] ?? null;

        return filled($value) ? (string) $value : null;
    }
}
