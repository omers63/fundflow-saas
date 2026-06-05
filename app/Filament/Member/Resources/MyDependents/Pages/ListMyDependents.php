<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyDependents\Pages;

use App\Filament\Member\Resources\MyDependents\MyDependentResource;
use App\Filament\Member\Widgets\MemberDependentsInsightsWidget;
use Filament\Resources\Pages\ListRecords;

class ListMyDependents extends ListRecords
{
    protected static string $resource = MyDependentResource::class;

    public function getSubheading(): ?string
    {
        return __('Review allocations and balances, update amounts, fund cash, or click a row to open the dependent portal.');
    }

    /**
     * @return array<class-string>
     */
    protected function getHeaderWidgets(): array
    {
        return [
            MemberDependentsInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }
}
