<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyContributions\Pages;

use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Filament\Member\Widgets\MyContributionsInsightsWidget;
use App\Filament\Support\MemberContributionFilamentActions;
use Filament\Resources\Pages\ListRecords;
use Filament\Support\Enums\Width;

class ListMyContributions extends ListRecords
{
    protected static string $resource = MyContributionResource::class;

    protected Width|string|null $maxContentWidth = Width::Full;

    /**
     * @return array<string>
     */
    public function getPageClasses(): array
    {
        return ['fi-page-member-contributions'];
    }

    public function getSubheading(): ?string
    {
        return __('Track your monthly cycles, posting status, cash readiness, and payment history.');
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            MyContributionsInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    protected function getHeaderActions(): array
    {
        return [
            MemberContributionFilamentActions::applyOpenPeriodContribution(),
        ];
    }
}
