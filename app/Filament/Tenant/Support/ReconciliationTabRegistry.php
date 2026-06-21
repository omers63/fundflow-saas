<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Filament\Tenant\Pages\ReconciliationOverviewPage;

final class ReconciliationTabRegistry
{
    /**
     * @return array<string, string>
     */
    public static function tabs(): array
    {
        return [
            'overview' => __('Overview'),
            'exceptions' => __('Exceptions'),
            'history' => __('History'),
            'snapshots' => __('Snapshots'),
            'methodology' => __('Methodology'),
        ];
    }

    public static function url(string $sideTab): string
    {
        return ReconciliationOverviewPage::getUrl(['sideTab' => $sideTab]);
    }
}
