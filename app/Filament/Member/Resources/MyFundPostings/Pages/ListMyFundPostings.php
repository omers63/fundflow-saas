<?php

namespace App\Filament\Member\Resources\MyFundPostings\Pages;

use App\Filament\Member\Resources\MyFundPostings\MyFundPostingResource;
use App\Filament\Member\Widgets\MemberFundPostingInsightsWidget;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListMyFundPostings extends ListRecords
{
    protected static string $resource = MyFundPostingResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            MemberFundPostingInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getSubheading(): ?string
    {
        return __('Submit deposits and track review status.');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('New deposit')),
        ];
    }
}
