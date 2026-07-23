<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Filament\Tenant\Pages\CommunicationsWorkspacePage;
use App\Filament\Tenant\Pages\Settings;

final class CommunicationsTabRegistry
{
    public const TAB_INBOX = 'inbox';

    public const TAB_ANNOUNCEMENTS = 'announcements';

    public const TAB_TEMPLATES = 'templates';

    public const TAB_DELIVERY = 'delivery';

    public const TAB_SETTINGS = 'settings';

    /**
     * @return array<string, string>
     */
    public static function tabs(): array
    {
        return [
            self::TAB_INBOX => __('Inbox'),
            self::TAB_ANNOUNCEMENTS => __('Announcements'),
            self::TAB_TEMPLATES => __('Templates'),
            self::TAB_DELIVERY => __('Delivery log'),
            self::TAB_SETTINGS => __('Settings'),
        ];
    }

    public static function url(string $tab): string
    {
        return match ($tab) {
            self::TAB_SETTINGS => Settings::getUrl(['tab' => 'communication::tab']),
            default => CommunicationsWorkspacePage::getUrl(['sideTab' => $tab]),
        };
    }

    public static function normalize(string $tab): string
    {
        return array_key_exists($tab, self::tabs()) ? $tab : self::TAB_INBOX;
    }
}
