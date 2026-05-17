<?php

namespace App\Filament\Tenant\Resources\FundTiers\Pages;

use App\Filament\Tenant\Resources\FundTiers\FundTierResource;
use App\Filament\Tenant\Widgets\LoanInsightsWidget;
use Filament\Resources\Pages\ListRecords;

class ListFundTiers extends ListRecords
{
    protected static string $resource = FundTierResource::class;

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
            'context' => 'fund_tiers',
        ];
    }

    public function getSubheading(): ?string
    {
        return __('Track master-fund pool allocation, deployment, and headroom per lending band.');
    }
}
