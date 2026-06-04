<?php

namespace App\Filament\Tenant\Resources\CashOutRequests\Pages;

use App\Filament\Tenant\Resources\CashOutRequests\CashOutRequestResource;
use App\Filament\Tenant\Widgets\CashOutRequestInsightsWidget;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListCashOutRequests extends ListRecords
{
    protected static string $resource = CashOutRequestResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->label(__('New cash out')),
        ];
    }

    protected function getHeaderWidgets(): array
    {
        return [
            CashOutRequestInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    public function getSubheading(): ?string
    {
        return __('Review member withdrawal requests, debit cash on approval, and clear against bank imports later.');
    }
}
