<?php

namespace App\Filament\Tenant\Resources\FundPostings\Pages;

use App\Filament\Tenant\Resources\FundPostings\FundPostingResource;
use App\Filament\Tenant\Widgets\FundPostingInsightsWidget;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFundPostings extends ListRecords
{
    protected static string $resource = FundPostingResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('New deposit')),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            FundPostingInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getSubheading(): ?string
    {
        return __('Review member deposits, track acceptance rates, and reconcile bank activity.');
    }
}
