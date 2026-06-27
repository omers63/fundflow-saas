<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

final class ReconciliationTabRegistry
{
    /**
     * @return array<string, string>
     */
    public static function tabs(bool $advancedUi = false): array
    {
        $tabs = [
            'overview' => __('Overview'),
            'exceptions' => __('Issues'),
            'history' => __('History'),
        ];

        if ($advancedUi) {
            $tabs['snapshots'] = __('Snapshots');
            $tabs['methodology'] = __('Methodology');
        }

        return $tabs;
    }

    public static function url(string $sideTab): string
    {
        return ReconciliationOverviewPage::getUrl(['sideTab' => $sideTab]);
    }
}
