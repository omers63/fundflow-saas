<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsClearing\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\SmsClearingQueueActions;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Setting;
use App\Models\Tenant\SmsTransaction;
use App\Support\SmsClearing\SmsClearingQueuePresenter;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

final class SmsClearingQueueTable
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
                    TextColumn::make('queue_slice')
                        ->label(__('Status'))
                        ->badge()
                        ->searchable(false)
                        ->sortable(false)
                        ->state(fn (SmsTransaction $record): string => SmsClearingQueuePresenter::sliceLabel($record))
                        ->color(fn (SmsTransaction $record): string => SmsClearingQueuePresenter::sliceColor($record)),
                    TextColumn::make('queue_kind')
                        ->label(__('Type'))
                        ->badge()
                        ->searchable(false)
                        ->sortable(false)
                        ->state(fn (SmsTransaction $record): string => SmsClearingQueuePresenter::kindLabel($record))
                        ->color(fn (SmsTransaction $record): string => SmsClearingQueuePresenter::kindColor($record)),
                    TextColumn::make('bank_name')
                        ->label(__('Bank'))
                        ->placeholder(__('—'))
                        ->toggleable(),
                    TextColumn::make('amount')
                        ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                        ->sortable()
                        ->color(fn (SmsTransaction $record): string => $record->transaction_type === 'credit' ? 'success' : 'danger'),
                    MemberTableColumns::relationNumber()
                        ->placeholder(__('Unassigned')),
                    TextColumn::make('member.name')
                        ->label(__('Member'))
                        ->placeholder(__('Unassigned'))
                        ->sortable(),
                    TextColumn::make('raw_sms')
                        ->label(__('SMS'))
                        ->limit(45)
                        ->tooltip(fn (SmsTransaction $record): string => (string) $record->raw_sms)
                        ->searchable()
                        ->wrap(),
                    TextColumn::make('suggested_action')
                        ->label(__('Next step'))
                        ->searchable(false)
                        ->sortable(false)
                        ->state(fn (SmsTransaction $record): string => SmsClearingQueuePresenter::suggestedActionLabel($record) ?? __('—'))
                        ->color('gray')
                        ->toggleable(isToggledHiddenByDefault: true),
                ])
                ->filters([
                    SelectFilter::make('member_id')
                        ->label(__('Member'))
                        ->relationship('member', 'name')
                        ->searchable()
                        ->preload(),
                    DateColumnRangeFilter::make('transaction_date', __('Transaction date')),
                ])
                ->recordUrl(fn (): ?string => null)
                ->recordAction(ViewAction::getDefaultName())
                ->emptyStateDescription(__('Open SMS import rows that need member assignment or posting to member cash.'))
                ->recordActions(TableRecordActionGroups::wrap(SmsClearingQueueActions::groupedRecordActions()))
                ->toolbarActions([
                    BulkActionGroup::make([
                        SmsClearingQueueActions::bulkAutoPost(),
                        SmsClearingQueueActions::bulkPostToCash(),
                        SmsClearingQueueActions::deleteBulk(),
                    ]),
                    TableToolbar::refreshBulkAction(),
                ])
                ->defaultSort('transaction_date', 'desc'),
            TableGrouping::smsTransactions()
        );
    }
}
