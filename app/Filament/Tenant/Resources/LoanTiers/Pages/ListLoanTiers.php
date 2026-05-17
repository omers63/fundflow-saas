<?php

namespace App\Filament\Tenant\Resources\LoanTiers\Pages;

use App\Filament\Tenant\Resources\LoanTiers\LoanTierResource;
use App\Filament\Tenant\Widgets\LoanInsightsWidget;
use Filament\Resources\Pages\ListRecords;

class ListLoanTiers extends ListRecords
{
    protected static string $resource = LoanTierResource::class;

    protected function getHeaderWidgets(): array
    {
        return [
            LoanInsightsWidget::class,
        ];
    }

    public function getHeaderWidgetsColumns(): int|array
    {
        return 1;
    }

    /**
     * @return array<string, mixed>
     */
    public function getWidgetData(): array
    {
        return [
            ...parent::getWidgetData(),
            'context' => 'loan_tiers',
        ];
    }

    public function getSubheading(): ?string
    {
        return __('Configure amount bands, installment floors, and how loans map to fund pools.');
    }
}
