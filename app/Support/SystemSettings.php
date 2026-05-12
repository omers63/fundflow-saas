<?php

namespace App\Support;

use App\Models\SystemSetting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;

class SystemSettings
{
    public static function all(): array
    {
        return Cache::remember('system_settings', 300, function (): array {
            $defaults = [
                'app_name' => 'FundFlow',
                'support_email' => null,
                'public_hero_title' => 'Family sponsorship made simple',
                'public_hero_subtitle' => 'Mobile-first enrollment and family collaboration.',
                'public_primary_color' => '#4f46e5',
                'public_secondary_color' => '#0ea5e9',
                'admin_primary_color' => '#4f46e5',
                'member_primary_color' => '#0284c7',
                'maintenance_enabled' => false,
                'maintenance_message' => 'We are performing scheduled maintenance.',
            ];

            try {
                if (!Schema::connection('central')->hasTable('system_settings')) {
                    return $defaults;
                }

                $setting = SystemSetting::query()->first();

                if (!$setting) {
                    return $defaults;
                }

                return [...$defaults, ...$setting->toArray()];
            } catch (\Throwable) {
                return $defaults;
            }
        });
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return static::all()[$key] ?? $default;
    }

    public static function clearCache(): void
    {
        Cache::forget('system_settings');
    }
}
