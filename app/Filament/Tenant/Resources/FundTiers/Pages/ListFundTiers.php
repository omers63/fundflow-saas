<?php

namespace App\Filament\Tenant\Resources\FundTiers\Pages;

use App\Filament\Tenant\Resources\FundTiers\FundTierResource;
use App\Filament\Tenant\Resources\FundTiers\Schemas\FundTierForm;
use App\Filament\Tenant\Widgets\LoanInsightsWidget;
use App\Models\Tenant\FundTier;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListFundTiers extends ListRecords
{
    protected static string $resource = FundTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->icon('heroicon-o-plus-circle')
                ->schema(fn (): array => FundTierForm::components())
                ->using(function (array $data): FundTier {
                    [$attributes, $loanTierIds] = FundTierForm::extractLoanTierIds($data);
                    $attributes['tier_number'] = FundTier::nextTierNumber();

                    $record = FundTier::query()->create($attributes);
                    $record->syncLoanTiers($loanTierIds);

                    return $record;
                }),
        ];
    }

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
        return __('Track master-fund pool allocation, deployment, and headroom per lending band. One fund tier may cover several loan amount bands.');
    }
}
