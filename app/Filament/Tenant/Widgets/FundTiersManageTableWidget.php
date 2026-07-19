<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Resources\FundTiers\Schemas\FundTierForm;
use App\Filament\Tenant\Resources\FundTiers\Tables\FundTiersTable;
use App\Models\Tenant\FundTier;
use Filament\Actions\CreateAction;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class FundTiersManageTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return null;
    }

    protected function getTableQuery(): Builder
    {
        return FundTier::query()->with('loanTiers');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->model(FundTier::class)
                ->label(__('New fund tier'))
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

    public function table(Table $table): Table
    {
        return FundTiersTable::configure($table)
            ->heading(__('Fund tiers'))
            ->description(__('One fund pool may cover several loan amount bands. Create and edit without leaving Settings.'));
    }
}
