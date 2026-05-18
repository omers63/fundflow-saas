<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Member\Widgets\MemberArrearsAlert;
use App\Filament\Member\Widgets\MemberPortalDashboardWidget;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\Width;

class MemberDashboard extends BaseDashboard
{
    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['fi-page-member-dashboard'];
    }

    /**
     * @return array<class-string>
     */
    public function getWidgets(): array
    {
        return [
            MemberPortalDashboardWidget::class,
            MemberArrearsAlert::class,
        ];
    }

    /**
     * @return int|array<string, int|null>
     */
    public function getColumns(): int|array
    {
        return 1;
    }
}
