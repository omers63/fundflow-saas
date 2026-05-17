<?php

namespace App\Filament\Tenant\Resources\Loans\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\LoanFilamentActions;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Setting;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class LoansTable
{
    public static function configure(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply(
            $table
                ->columnManager(true)
                ->columns([
                    TextColumn::make('member.name')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('amount_requested')
                        ->label(__('Requested'))
                        ->money($currency)
                        ->sortable(),
                    TextColumn::make('amount_approved')
                        ->label(__('Approved'))
                        ->money($currency)
                        ->placeholder(__('—')),
                    TextColumn::make('queue_position')
                        ->label(__('Queue'))
                        ->placeholder(__('—')),
                    TextColumn::make('loanTier.label')
                        ->label(__('Tier'))
                        ->placeholder(__('—')),
                    TextColumn::make('outstanding')
                        ->label(__('Outstanding'))
                        ->state(fn (Loan $record): float => $record->getOutstandingBalance())
                        ->money($currency),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => Loan::statusOptions()[$state] ?? $state)
                        ->color(fn (string $state): string => Loan::statusColor($state)),
                    TextColumn::make('applied_at')
                        ->dateTime()
                        ->sortable(),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options(Loan::statusOptions()),
                    DateColumnRangeFilter::make('applied_at', 'Applied'),
                ])
                ->recordActions(TableRecordActionGroups::wrap([
                    ViewAction::make(),
                    EditAction::make()
                        ->hidden(fn (Loan $record): bool => ! in_array($record->status, ['pending', 'approved'], true)),
                    ...LoanFilamentActions::workflowActions(),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make(LoanFilamentActions::bulkActions()),
                ])
                ->defaultSort('applied_at', 'desc'),
            TableGrouping::loans()
        );
    }
}
