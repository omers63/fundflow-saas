<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

final class NotificationSettings
{
    public const GROUP = 'notifications';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'sms_enabled' => false,
            'whatsapp_enabled' => false,
            'twilio_account_sid' => '',
            'twilio_auth_token' => '',
            'twilio_sms_from' => '',
            'twilio_whatsapp_from' => '',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return array_merge(self::defaults(), Setting::getGroup(self::GROUP));
    }

    public static function smsEnabled(): bool
    {
        return (bool) self::get('sms_enabled', false);
    }

    public static function whatsappEnabled(): bool
    {
        return (bool) self::get('whatsapp_enabled', false);
    }

    public static function twilioConfigured(): bool
    {
        return filled(self::get('twilio_account_sid'))
            && filled(self::get('twilio_auth_token'));
    }

    public static function twilioSmsFrom(): ?string
    {
        $from = (string) self::get('twilio_sms_from', '');

        return filled($from) ? $from : null;
    }

    public static function twilioWhatsAppFrom(): ?string
    {
        $from = (string) self::get('twilio_whatsapp_from', '');

        return filled($from) ? $from : null;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): void
    {
        foreach (self::defaults() as $key => $default) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            Setting::set(self::GROUP, $key, $values[$key]);
        }
    }

    private static function get(string $key, mixed $default = null): mixed
    {
        $all = self::all();

        return $all[$key] ?? $default;
    }
}
