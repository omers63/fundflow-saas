<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Widgets;

use App\Filament\Tenant\Resources\LoanTiers\Schemas\LoanTierForm;
use App\Filament\Tenant\Resources\LoanTiers\Tables\LoanTiersTable;
use App\Models\Tenant\LoanTier;
use Filament\Actions\CreateAction;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;

class LoanTiersManageTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return null;
    }

    protected function getTableQuery(): Builder
    {
        return LoanTier::query()->with('fundTier');
    }

    protected function getHeaderActions(): array
    {
        return [
            CreateAction::make()
                ->model(LoanTier::class)
                ->label(__('New loan tier'))
                ->schema(fn (): array => LoanTierForm::components())
                ->using(function (array $data): LoanTier {
                    $data['tier_number'] = LoanTier::nextTierNumber();

                    return LoanTier::query()->create($data);
                }),
        ];
    }

    public function table(Table $table): Table
    {
        return LoanTiersTable::configure($table)
            ->heading(__('Loan tiers'))
            ->description(__('Amount bands and installment floors. Link each band to a fund pool when ready.'));
    }
}
