<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\SmsTransactions\Tables;

use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\SmsImportSession;
use App\Models\Tenant\SmsTransaction;
use App\Services\AccountingService;
use App\Support\Lang;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

final class SmsTransactionsTable
{
    public static function configure(Table $table, bool $embedInBankWorkspace = false): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');
        $memberOptions = fn (): array => Member::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Member $member): array => [
                $member->id => trim(($member->member_number ? $member->member_number.' — ' : '').$member->name),
            ])
            ->all();

        return TableGrouping::apply($table
            ->heading($embedInBankWorkspace ? null : __('SMS transactions'))
            ->description($embedInBankWorkspace
                ? __('Review parsed SMS transactions and post verified rows to member cash.')
                : null)
            ->columns([
                TextColumn::make('bank_name')
                    ->label(__('Bank'))
                    ->placeholder(__('—'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('transaction_date')
                    ->label(__('Date'))
                    ->date('d M Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->money($currency)
                    ->sortable()
                    ->color(fn (SmsTransaction $record): string => $record->transaction_type === 'credit' ? 'success' : 'danger'),
                TextColumn::make('transaction_type')
                    ->label(__('Type'))
                    ->badge()
                    ->color(fn (string $state): string => $state === 'credit' ? 'success' : 'danger'),
                TextColumn::make('reference')
                    ->placeholder(__('—'))
                    ->searchable(),
                TextColumn::make('member.name')
                    ->label(__('Member'))
                    ->placeholder(__('—'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('raw_sms')
                    ->label(__('SMS'))
                    ->limit(55)
                    ->tooltip(fn (SmsTransaction $record): string => (string) $record->raw_sms)
                    ->searchable(),
                IconColumn::make('posted_at')
                    ->label(__('Posted'))
                    ->boolean()
                    ->getStateUsing(fn (SmsTransaction $record): bool => $record->isPosted())
                    ->trueIcon('heroicon-o-check-badge')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('gray'),
                IconColumn::make('is_duplicate')
                    ->label(__('Dup.'))
                    ->boolean()
                    ->trueColor('warning')
                    ->falseColor('success')
                    ->trueIcon('heroicon-o-exclamation-triangle')
                    ->falseIcon('heroicon-o-check-circle'),
            ])
            ->defaultSort('transaction_date', 'desc')
            ->filters([
                SelectFilter::make('bank_name')
                    ->label(__('Bank'))
                    ->options(fn (): array => SmsTransaction::query()
                        ->whereNotNull('bank_name')
                        ->distinct()
                        ->orderBy('bank_name')
                        ->pluck('bank_name', 'bank_name')
                        ->all()),
                SelectFilter::make('import_session_id')
                    ->label(__('Import session'))
                    ->options(fn (): array => SmsImportSession::query()
                        ->latest()
                        ->get()
                        ->mapWithKeys(fn (SmsImportSession $session): array => [
                            $session->id => trim(($session->bank_name ?? __('No bank')).' — '.$session->filename.' ('.$session->created_at?->format('d M Y').')'),
                        ])
                        ->all()),
                SelectFilter::make('transaction_type')
                    ->options(Lang::transOptions([
                        'credit' => __('Credit'),
                        'debit' => __('Debit'),
                    ])),
                TernaryFilter::make('has_member')
                    ->label(__('Member matched'))
                    ->trueLabel(__('Matched only'))
                    ->falseLabel(__('Unmatched only'))
                    ->placeholder(__('All'))
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('member_id'),
                        false: fn ($query) => $query->whereNull('member_id'),
                    ),
                TernaryFilter::make('posted')
                    ->label(__('Posting status'))
                    ->trueLabel(__('Posted only'))
                    ->falseLabel(__('Unposted only'))
                    ->placeholder(__('All'))
                    ->queries(
                        true: fn ($query) => $query->whereNotNull('posted_at'),
                        false: fn ($query) => $query->whereNull('posted_at'),
                    ),
                TernaryFilter::make('is_duplicate')
                    ->label(__('Duplicates'))
                    ->trueLabel(__('Duplicates only'))
                    ->falseLabel(__('Non-duplicates only'))
                    ->placeholder(__('All')),
                Filter::make('date_range')
                    ->schema([
                        DatePicker::make('date_from')->label(__('From')),
                        DatePicker::make('date_to')->label(__('To')),
                    ])
                    ->query(fn ($query, array $data) => $query
                        ->when($data['date_from'] ?? null, fn ($q, $value) => $q->whereDate('transaction_date', '>=', $value))
                        ->when($data['date_to'] ?? null, fn ($q, $value) => $q->whereDate('transaction_date', '<=', $value)))
                    ->columns(2),
                SelectFilter::make('member_id')
                    ->label(__('Member'))
                    ->searchable()
                    ->options($memberOptions),
                Filter::make('amount')
                    ->schema([
                        TextInput::make('amount_min')->label(__('Min amount'))->numeric(),
                        TextInput::make('amount_max')->label(__('Max amount'))->numeric(),
                    ])
                    ->columns(2)
                    ->query(function ($query, array $data) {
                        return $query
                            ->when(filled($data['amount_min'] ?? null), fn ($q) => $q->where('amount', '>=', $data['amount_min']))
                            ->when(filled($data['amount_max'] ?? null), fn ($q) => $q->where('amount', '<=', $data['amount_max']));
                    }),
                TrashedFilter::make(),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                ViewAction::make(),
                Action::make('postToCash')
                    ->label(__('Post to cash'))
                    ->icon('heroicon-o-arrow-right-circle')
                    ->color('primary')
                    ->visible(fn (SmsTransaction $record): bool => ! $record->isPosted())
                    ->fillForm(fn (SmsTransaction $record): array => ['member_id' => $record->member_id])
                    ->schema([
                        Select::make('member_id')
                            ->label(__('Post for member'))
                            ->options($memberOptions)
                            ->searchable()
                            ->required()
                            ->helperText(__('Auto-matched from SMS template, or select manually.')),
                    ])
                    ->action(function (SmsTransaction $record, array $data): void {
                        $member = Member::query()->findOrFail($data['member_id']);
                        app(AccountingService::class)->postSmsTransactionToCash($record, $member);

                        Notification::make()
                            ->title(__('Posted to cash account'))
                            ->body(__('SMS transaction posted for :name.', ['name' => $member->name]))
                            ->success()
                            ->send();
                    }),
                DeleteAction::make()
                    ->modalDescription(__('Soft-deletes this SMS import row. If it was posted to cash, matching ledger lines are reversed first.'))
                    ->using(function (SmsTransaction $record): bool {
                        app(AccountingService::class)->safeDeleteSmsTransaction($record);

                        return true;
                    }),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->modalDescription(__('Deletes selected rows; posted transactions are reversed from the ledger first.'))
                        ->using(function (DeleteBulkAction $action, Collection $records): void {
                            $accounting = app(AccountingService::class);

                            foreach ($records as $record) {
                                try {
                                    $accounting->safeDeleteSmsTransaction($record);
                                } catch (\Throwable $e) {
                                    $action->reportBulkProcessingFailure(message: $e->getMessage());
                                    report($e);
                                }
                            }
                        }),
                    BulkAction::make('bulkAutoPost')
                        ->label(__('Auto-post matched transactions'))
                        ->icon('heroicon-o-bolt')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalDescription(__('Posts selected rows that already have an auto-matched member. Others are skipped.'))
                        ->action(function (Collection $records): void {
                            $service = app(AccountingService::class);
                            $posted = 0;
                            $skipped = 0;

                            foreach ($records as $tx) {
                                if ($tx->isPosted() || $tx->member_id === null) {
                                    $skipped++;

                                    continue;
                                }

                                $member = Member::query()->find($tx->member_id);

                                if ($member === null) {
                                    $skipped++;

                                    continue;
                                }

                                $service->postSmsTransactionToCash($tx, $member);
                                $posted++;
                            }

                            Notification::make()
                                ->title(__('Auto-post complete'))
                                ->body(__('Posted: :posted | Skipped: :skipped', ['posted' => $posted, 'skipped' => $skipped]))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('bulkPostToCash')
                        ->label(__('Bulk post to a single member'))
                        ->icon('heroicon-o-arrow-right-circle')
                        ->color('primary')
                        ->schema([
                            Select::make('member_id')
                                ->label(__('Post all selected for member'))
                                ->options($memberOptions)
                                ->searchable()
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            $member = Member::query()->findOrFail($data['member_id']);
                            $service = app(AccountingService::class);
                            $posted = 0;
                            $skipped = 0;

                            foreach ($records as $tx) {
                                if ($tx->isPosted()) {
                                    $skipped++;

                                    continue;
                                }

                                $service->postSmsTransactionToCash($tx, $member);
                                $posted++;
                            }

                            Notification::make()
                                ->title(__('Bulk post complete'))
                                ->body(__('Posted: :posted | Already posted (skipped): :skipped', ['posted' => $posted, 'skipped' => $skipped]))
                                ->success()
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::smsTransactions());
    }
}
