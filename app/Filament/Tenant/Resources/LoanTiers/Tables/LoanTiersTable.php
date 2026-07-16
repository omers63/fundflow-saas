<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\LoanTiers\Tables;

use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\LoanTiers\Schemas\LoanTierForm;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanQueueOrderingService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class LoanTiersTable
{
    public static function configure(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->columnManager(true)
            ->modifyQueryUsing(fn ($query) => $query->with('fundTier'))
            ->columns([
                TextColumn::make('tier_number')
                    ->sortable(),
                TextColumn::make('label'),
                TextColumn::make('min_amount')
                    ->money($currency),
                TextColumn::make('max_amount')
                    ->money($currency),
                TextColumn::make('min_monthly_installment')
                    ->label(__('Min installment'))
                    ->money($currency),
                TextColumn::make('fundTier.label')
                    ->label(__('Fund pool'))
                    ->placeholder(__('—'))
                    ->badge(),
                ToggleColumn::make('is_active')
                    ->label(__('Active')),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('Active')),
                SelectFilter::make('fund_tier_id')
                    ->label(__('Fund pool'))
                    ->options(fn (): array => FundTier::query()
                        ->where('tier_number', '>', 0)
                        ->orderBy('tier_number')
                        ->pluck('label', 'id')
                        ->all())
                    ->searchable()
                    ->preload(),
            ])
            ->defaultSort('tier_number')
            ->recordActions(TableRecordActionGroups::wrap([
                EditAction::make()
                    ->schema(fn (?LoanTier $record = null): array => LoanTierForm::components($record))
                    ->fillForm(fn (LoanTier $record): array => LoanTierForm::fillData($record))
                    ->action(function (array $data, LoanTier $record, EditAction $action): void {
                        $previousFundTierId = $record->fund_tier_id !== null
                            ? (int) $record->fund_tier_id
                            : null;

                        $record->update($data);

                        $resequenced = LoanQueueOrderingService::realignLoansToCurrentFundMapping([(int) $record->id]);

                        if (
                            $previousFundTierId !== null
                            && ! in_array($previousFundTierId, $resequenced, true)
                        ) {
                            LoanQueueOrderingService::resequenceFundTier($previousFundTierId);
                        }

                        $action->success();
                    }),
                DeleteAction::make(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make(),
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::configurationTiers())
            ->recordUrl(fn (): ?string => null)
            ->recordAction(EditAction::getDefaultName());
    }
}
