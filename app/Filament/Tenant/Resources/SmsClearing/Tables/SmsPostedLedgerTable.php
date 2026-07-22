<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsClearing\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Support\ViewSmsTransactionAction;
use App\Models\Tenant\Setting;
use App\Models\Tenant\SmsTransaction;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

final class SmsPostedLedgerTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            $table
                ->columns([
                    TextColumn::make('transaction_date')
                        ->label(__('Date'))
                        ->date('d M Y')
                        ->sortable(),
                    TextColumn::make('bank_name')
                        ->label(__('Bank'))
                        ->placeholder(__('—'))
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('amount')
                        ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                        ->sortable()
                        ->color(fn (SmsTransaction $record): string => $record->transaction_type === 'credit' ? 'success' : 'danger'),
                    TextColumn::make('transaction_type')
                        ->label(__('Type'))
                        ->badge()
                        ->color(fn (string $state): string => $state === 'credit' ? 'success' : 'danger'),
                    MemberTableColumns::relationNumber()
                        ->placeholder(__('—')),
                    TextColumn::make('member.name')
                        ->label(__('Member'))
                        ->placeholder(__('—'))
                        ->searchable()
                        ->wrap(),
                    TextColumn::make('posted_at')
                        ->label(__('Posted at'))
                        ->dateTime('d M Y H:i')
                        ->sortable(),
                    TextColumn::make('postedBy.name')
                        ->label(__('Posted by'))
                        ->placeholder(__('—'))
                        ->toggleable(),
                    IconColumn::make('is_duplicate')
                        ->label(__('Dup.'))
                        ->boolean()
                        ->trueColor('warning')
                        ->falseColor('success')
                        ->trueIcon('heroicon-o-exclamation-triangle')
                        ->falseIcon('heroicon-o-check-circle')
                        ->toggleable(isToggledHiddenByDefault: true),
                ])
                ->filters([
                    DateColumnRangeFilter::make('transaction_date', __('Transaction date')),
                ])
                ->recordActions(TableRecordActionGroups::wrap([
                    ViewSmsTransactionAction::make(),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ])
                ->defaultSort('posted_at', 'desc')
                ->emptyStateDescription(__('Posted SMS rows cleared to member cash.')),
            TableGrouping::smsTransactions()
        );
    }
}
