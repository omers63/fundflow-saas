<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\FundTiers\Tables;

use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\FundTiers\Schemas\FundTierForm;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanQueueOrderingService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Columns\ToggleColumn;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;

class FundTiersTable
{
    public static function configure(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->columnManager(true)
            ->columns([
                TextColumn::make('tier_number')
                    ->sortable(),
                TextColumn::make('label'),
                TextColumn::make('loan_tiers')
                    ->label(__('Loan tiers'))
                    ->state(fn (FundTier $record): string => $record->loanTiers
                        ->sortBy('tier_number')
                        ->map(fn ($tier) => $tier->label)
                        ->implode(', ') ?: __('—'))
                    ->wrap()
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('percentage')
                    ->suffix('%'),
                TextColumn::make('declared_pool')
                    ->label(__('Declared pool'))
                    ->state(fn (FundTier $record): float => $record->allocated_amount)
                    ->money($currency)
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('tier_available')
                    ->label(__('Available'))
                    ->state(fn (FundTier $record): float => $record->available_amount)
                    ->money($currency)
                    ->searchable(false)
                    ->sortable(false),
                TextColumn::make('active_loans_count')
                    ->label(__('Active loans'))
                    ->state(fn (FundTier $record): int => $record->active_loans_count)
                    ->searchable(false)
                    ->sortable(false),
                ToggleColumn::make('is_active')
                    ->label(__('Active')),
            ])
            ->filters([
                TernaryFilter::make('is_active')
                    ->label(__('Active')),
            ])
            ->defaultSort('tier_number')
            ->modifyQueryUsing(fn ($query) => $query->with('loanTiers'))
            ->recordActions(TableRecordActionGroups::wrap([
                EditAction::make()
                    ->schema(fn (?FundTier $record = null): array => FundTierForm::components($record))
                    ->fillForm(fn (FundTier $record): array => FundTierForm::fillData($record))
                    ->action(function (array $data, FundTier $record, EditAction $action): void {
                        [$attributes, $loanTierIds] = FundTierForm::extractLoanTierIds($data);
                        $record->update($attributes);
                        $record->syncLoanTiers($loanTierIds);
                        $action->success();
                    }),
                Action::make('resequence')
                    ->label(__('Resequence queue'))
                    ->icon('heroicon-o-arrows-up-down')
                    ->action(function (FundTier $record): void {
                        LoanQueueOrderingService::resequenceFundTier($record->id);
                        Notification::make()->title(__('Queue resequenced'))->success()->send();
                    }),
                DeleteAction::make()
                    ->visible(fn (FundTier $record): bool => ! $record->isEmergency()),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->using(function (DeleteBulkAction $action, $records): void {
                            foreach ($records as $record) {
                                if (! $record instanceof FundTier || $record->isEmergency()) {
                                    continue;
                                }

                                $record->delete();
                            }
                        }),
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::configurationTiers())
            ->recordUrl(fn (): ?string => null)
            ->recordAction(EditAction::getDefaultName());
    }
}
