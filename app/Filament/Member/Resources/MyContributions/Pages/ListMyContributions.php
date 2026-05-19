<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyContributions\Pages;

use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use App\Filament\Member\Widgets\MyContributionsInsightsWidget;
use Filament\Resources\Pages\ListRecords;

class ListMyContributions extends ListRecords
{
    protected static string $resource = MyContributionResource::class;

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
}
