<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Filament\Tenant\Pages\Settings;

final class SettingsTabRegistry
{
    /**
     * Filament settingsTab query keys => navigation label.
     *
     * @return array<string, string>
     */
    public static function tabs(): array
    {
        return [
            'general::tab' => __('General'),
            'collection::tab' => __('Collection'),
            'loans::tab' => __('Loans'),
            'fund-tiers::tab' => __('Fund tiers'),
            'reconciliation::tab' => __('Reconciliation'),
            'guarantor-rules::tab' => __('Guarantor rules'),
            'fiscal-calendar::tab' => __('Fiscal calendar'),
            'public-page::tab' => __('Public page'),
            'statements::tab' => __('Statements'),
            'communication::tab' => __('Communication'),
            'notifications::tab' => __('Notifications'),
            'bank-templates::tab' => __('Bank templates'),
            'sms-templates::tab' => __('SMS templates'),
        ];
    }

    public static function url(string $tabKey): string
    {
        return Settings::getUrl(['settingsTab' => $tabKey]);
    }
}
