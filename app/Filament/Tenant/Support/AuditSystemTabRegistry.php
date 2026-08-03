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
            'remittances' => __('Outbound remittances'),
            'inbound_remittances' => __('Inbound remittances'),
            'access' => __('Access log'),
            'notifications' => __('Notification log'),
            'jobs' => __('Automation'),
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

    public static function url(string $sideTab, array $query = []): string
    {
        return AuditSystemPage::getUrl(array_merge(['sideTab' => $sideTab], $query));
    }
}
