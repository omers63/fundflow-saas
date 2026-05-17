<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Loans\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Setting;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class InstallmentsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'installments';

    protected static ?string $title = 'Repayment schedule';

    public function table(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return $table
            ->columnManager(true)
            ->columns([
                TextColumn::make('installment_number')
                    ->label(__('#'))
                    ->sortable(),
                TextColumn::make('due_date')
                    ->label(__('Due'))
                    ->date()
                    ->sortable(),
                TextColumn::make('amount')
                    ->money($currency),
                TextColumn::make('late_fee_amount')
                    ->label(__('Late fee'))
                    ->money($currency)
                    ->placeholder(__('—')),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (string $state): string => match ($state) {
                        'pending' => __('Pending'),
                        'paid' => __('Paid'),
                        'overdue' => __('Overdue'),
                        default => $state,
                    })
                    ->color(fn (string $state): string => match ($state) {
                        'paid' => 'success',
                        'overdue' => 'danger',
                        default => 'warning',
                    }),
                TextColumn::make('paid_at')
                    ->dateTime()
                    ->placeholder(__('—')),
                TextColumn::make('paid_by_guarantor')
                    ->label(__('Guarantor paid'))
                    ->formatStateUsing(fn (bool $state): string => $state ? __('Yes') : __('No'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->defaultSort('installment_number')
            ->recordActions(TableRecordActionGroups::wrap([]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->emptyStateHeading(__('No installments'))
            ->emptyStateDescription(__('Installments are created when the loan is fully disbursed.'));
    }
}
