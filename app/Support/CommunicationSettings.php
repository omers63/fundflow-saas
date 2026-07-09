<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

final class CommunicationSettings
{
    public const GROUP = 'communication';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'in_app_enabled' => true,
            'email_enabled' => true,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function allForForm(): array
    {
        $all = array_merge(self::defaults(), Setting::getGroup(self::GROUP));

        return [
            'communication_in_app_enabled' => (bool) ($all['in_app_enabled'] ?? true),
            'communication_email_enabled' => (bool) ($all['email_enabled'] ?? true),
        ];
    }

    public static function inAppEnabled(): bool
    {
        return (bool) self::get('in_app_enabled', true);
    }

    public static function emailEnabled(): bool
    {
        return (bool) self::get('email_enabled', true);
    }

    public static function channelEnabled(string $channel): bool
    {
        return match ($channel) {
            'in_app' => self::inAppEnabled(),
            'push' => WebPushNotification::enabled(),
            'email' => self::emailEnabled(),
            'sms' => NotificationSettings::smsEnabled() && NotificationSettings::twilioConfigured(),
            'whatsapp' => NotificationSettings::whatsappEnabled() && NotificationSettings::twilioConfigured(),
            default => false,
        };
    }

    /**
     * @return list<string>
     */
    public static function enabledLogicalChannels(): array
    {
        return array_values(array_filter(
            ['in_app', 'push', 'email', 'sms', 'whatsapp'],
            fn (string $channel): bool => self::channelEnabled($channel),
        ));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function saveFromForm(array $state): void
    {
        Setting::set(self::GROUP, 'in_app_enabled', (bool) ($state['communication_in_app_enabled'] ?? true));
        Setting::set(self::GROUP, 'email_enabled', (bool) ($state['communication_email_enabled'] ?? true));
    }

    private static function get(string $key, mixed $default): mixed
    {
        $value = Setting::get(self::GROUP, $key);

        return $value !== null ? $value : $default;
    }
}
