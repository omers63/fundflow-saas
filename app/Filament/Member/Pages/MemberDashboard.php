<?php

declare(strict_types=1);

namespace App\Filament\Member\Pages;

use App\Filament\Concerns\TranslatesPageNavigationLabel;
use App\Filament\Member\Support\ReturnToParentPortalAction;
use App\Filament\Member\Widgets\MemberPortalDashboardWidget;
use BackedEnum;
use Filament\Actions\Action;
use Filament\Pages\Dashboard as BaseDashboard;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;

class MemberDashboard extends BaseDashboard
{
    use TranslatesPageNavigationLabel;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHome;

    protected static ?string $navigationLabel = 'Overview';

    protected Width|string|null $maxContentWidth = Width::Full;

    public function getTitle(): string
    {
        return __('Overview');
    }

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
        ];
    }

    /**
     * @return int|array<string, int|null>
     */
    public function getColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<Action>
     */
    protected function getHeaderActions(): array
    {
        return [
            ReturnToParentPortalAction::make($this),
        ];
    }
}
