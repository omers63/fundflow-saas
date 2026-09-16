<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

final class SadadSettings
{
    public const GROUP = 'sadad';

    public static function enabled(): bool
    {
        return (string) Setting::get(self::GROUP, 'enabled', '0') === '1';
    }

    public static function billerId(): string
    {
        return (string) Setting::get(self::GROUP, 'biller_id', '');
    }

    public static function registrationStatus(): string
    {
        return (string) Setting::get(self::GROUP, 'registration_status', 'not_started');
    }

    public static function merchantCode(): string
    {
        return (string) Setting::get(self::GROUP, 'merchant_code', '');
    }

    /**
     * @param  array{enabled?: bool, biller_id?: string, registration_status?: string, merchant_code?: string}  $data
     */
    public static function save(array $data): void
    {
        if (array_key_exists('enabled', $data)) {
            Setting::set(self::GROUP, 'enabled', !empty($data['enabled']) ? '1' : '0');
        }
        if (array_key_exists('biller_id', $data)) {
            Setting::set(self::GROUP, 'biller_id', (string) $data['biller_id']);
        }
        if (array_key_exists('registration_status', $data)) {
            Setting::set(self::GROUP, 'registration_status', (string) $data['registration_status']);
        }
        if (array_key_exists('merchant_code', $data)) {
            Setting::set(self::GROUP, 'merchant_code', (string) $data['merchant_code']);
        }
    }

    /**
     * @return array{enabled: string, biller_id: string, registration_status: string, merchant_code: string}
     */
    public static function defaults(): array
    {
        return [
            'enabled' => '0',
            'biller_id' => '',
            'registration_status' => 'not_started',
            'merchant_code' => '',
        ];
    }
}
