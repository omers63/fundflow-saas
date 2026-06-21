<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Filament\Tenant\Pages\AuditSystemPage;

final class AuditSystemTabRegistry
{
    /**
     * @return array<string, string>
     */
    public static function tabs(): array
    {
        return [
            'audit' => __('Audit log'),
            'notifications' => __('Notification log'),
            'jobs' => __('Jobs'),
            'maintenance' => __('Maintenance'),
            'migration' => __('Migration'),
            'fiscal' => __('Year-end close'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function tabsForUser(bool $isAdmin): array
    {
        $tabs = self::tabs();

        if (! $isAdmin) {
            unset($tabs['maintenance'], $tabs['migration']);
        }

        return $tabs;
    }

    public static function url(string $sideTab): string
    {
        return AuditSystemPage::getUrl(['sideTab' => $sideTab]);
    }
}
