<?php

namespace App\Filament\Tenant\Resources\LoanTiers\Pages;

use App\Filament\Tenant\Resources\LoanTiers\LoanTierResource;
use App\Filament\Tenant\Resources\LoanTiers\Schemas\LoanTierForm;
use App\Filament\Tenant\Widgets\LoanInsightsWidget;
use App\Models\Tenant\LoanTier;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListLoanTiers extends ListRecords
{
    protected static string $resource = LoanTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->schema(fn (): array => LoanTierForm::components())
                ->using(function (array $data): LoanTier {
                    $data['tier_number'] = LoanTier::nextTierNumber();

                    return LoanTier::query()->create($data);
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
            'context' => 'loan_tiers',
        ];
    }

    public function getSubheading(): ?string
    {
        return __('Configure amount bands and installment floors. Link each band to a fund pool (or leave unassigned).');
    }
}
