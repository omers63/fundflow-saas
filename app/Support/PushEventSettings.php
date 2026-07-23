<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;
use App\Services\Tenant\NotificationPreferenceService;
use NotificationChannels\WebPush\WebPushChannel;

final class PushEventSettings
{
    public const GROUP = 'push_events';

    /**
     * Events that support browser push (catalog keys → translated labels).
     *
     * @return array<string, string>
     */
    public static function eventOptions(): array
    {
        $options = [];

        foreach (NotificationTemplateCatalog::definitions() as $key => $definition) {
            $supported = $definition['supported'] ?? [];

            if (! in_array(NotificationPreferenceService::CH_PUSH, $supported, true)) {
                continue;
            }

            $options[$key] = __($definition['label']);
        }

        return $options;
    }

    /**
     * @return array<string, mixed>
     */
    public static function allForForm(): array
    {
        return [
            'push_events_enabled' => self::enabledKeys(),
        ];
    }

    /**
     * @return list<string>
     */
    public static function enabledKeys(): array
    {
        $stored = Setting::getGroup(self::GROUP);
        $enabled = [];

        foreach (array_keys(self::eventOptions()) as $key) {
            if (! array_key_exists($key, $stored) || filter_var($stored[$key], FILTER_VALIDATE_BOOL)) {
                $enabled[] = $key;
            }
        }

        return $enabled;
    }

    public static function enabledFor(string $templateKey): bool
    {
        if (! array_key_exists($templateKey, self::eventOptions())) {
            return true;
        }

        $value = Setting::get(self::GROUP, $templateKey);

        if ($value === null) {
            return true;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }

    /**
     * @param  list<string|class-string>  $channels
     * @return list<string|class-string>
     */
    public static function filterChannels(array $channels, ?string $templateKey): array
    {
        if ($templateKey === null || self::enabledFor($templateKey)) {
            return $channels;
        }

        return array_values(array_filter(
            $channels,
            fn (mixed $channel): bool => $channel !== WebPushChannel::class,
        ));
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function saveFromForm(array $state): void
    {
        $selected = $state['push_events_enabled'] ?? [];

        if (! is_array($selected)) {
            $selected = [];
        }

        foreach (array_keys(self::eventOptions()) as $key) {
            Setting::set(
                self::GROUP,
                $key,
                in_array($key, $selected, true) ? '1' : '0',
            );
        }
    }
}
