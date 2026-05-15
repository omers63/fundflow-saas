<?php

namespace App\Filament\Tenant\Resources\BankAccounts\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\ViewActions\ViewBankTransactionAction;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\FundFlowService;
use App\Services\FundPostingService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class BankTransactionsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->columns([
                TextColumn::make('transaction_date')
                    ->date()
                    ->sortable(),
                TextColumn::make('bankStatement.filename')
                    ->label('Source')
                    ->limit(20),
                TextColumn::make('amount')
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable()
                    ->color(fn ($state): string => $state >= 0 ? 'success' : 'danger'),
                TextColumn::make('member.name')
                    ->label('Assigned to')
                    ->placeholder(__('Unassigned'))
                    ->sortable(),
                TextColumn::make('masterCashTransaction.id')
                    ->label(__('Master cash'))
                    ->placeholder(__('—'))
                    ->formatStateUsing(fn ($state, BankTransaction $record): string => $record->masterCashMirrorSummary() ?? __('—'))
                    ->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'imported' => 'warning',
                        'mirrored' => 'info',
                        'posted' => 'success',
                        'ignored' => 'gray',
                        'duplicate' => 'danger',
                    }),
                TextColumn::make('duplicateOf.description')
                    ->label('Duplicate of')
                    ->placeholder(__('—'))
                    ->limit(30)
                    ->toggledHiddenByDefault(),
                IconColumn::make('is_cleared')
                    ->label('Cleared')
                    ->boolean(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'imported' => 'Imported',
                        'mirrored' => 'Mirrored',
                        'posted' => 'Posted',
                        'ignored' => 'Ignored',
                        'duplicate' => 'Duplicate',
                    ]),
                TernaryFilter::make('is_cleared')
                    ->label('Cleared status')
                    ->trueLabel(__('Cleared'))
                    ->falseLabel(__('Uncleared')),
                DateColumnRangeFilter::make('transaction_date', 'Transaction date'),
                SelectFilter::make('member_id')
                    ->label('Member')
                    ->relationship('member', 'name')
                    ->searchable()
                    ->preload(),
                TernaryFilter::make('duplicate_of_id')
                    ->label('Duplicate link')
                    ->nullable()
                    ->trueLabel(__('Linked'))
                    ->falseLabel(__('Not linked')),
                SelectFilter::make('bank_statement_id')
                    ->label('Statement')
                    ->relationship('bankStatement', 'filename')
                    ->searchable()
                    ->preload(),
                Filter::make('description_contains')
                    ->label('Description')
                    ->schema([
                        TextInput::make('value')
                            ->label('Contains'),
                    ])
                    ->query(function (Builder $query, array $data): Builder {
                        $needle = $data['value'] ?? null;

                        return $query->when(
                            filled($needle),
                            fn (Builder $query): Builder => $query->where('description', 'like', '%'.addcslashes((string) $needle, '%_\\').'%'),
                        );
                    })
                    ->indicateUsing(function (array $data): array {
                        if (! filled($data['value'] ?? null)) {
                            return [];
                        }

                        return [
                            Indicator::make(__('Description: :value', ['value' => $data['value']]))
                                ->removeField('value'),
                        ];
                    }),
            ])
            ->recordUrl(fn (): ?string => null)
            ->recordAction(ViewAction::getDefaultName())
            ->recordActions([
                ViewBankTransactionAction::make(),
                Action::make('mirrorToCash')
                    ->label('Mirror to cash')
                    ->icon('heroicon-o-arrow-right')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalDescription(__('Mirror this transaction to the Master Cash account.'))
                    ->hidden(fn ($record) => $record->status !== 'imported')
                    ->action(function ($record, FundFlowService $service) {
                        $service->mirrorToCash([$record->id]);
                        Notification::make()->title(__('Transaction mirrored to cash'))->success()->send();
                    }),
                Action::make('postToMember')
                    ->label('Post to member')
                    ->icon('heroicon-o-user-plus')
                    ->color('success')
                    ->requiresConfirmation()
                    ->hidden(fn ($record) => $record->status !== 'mirrored')
                    ->form([
                        Select::make('member_id')
                            ->label('Member')
                            ->options(fn () => Member::active()->pluck('name', 'id'))
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function ($record, array $data, FundFlowService $service) {
                        $member = Member::findOrFail($data['member_id']);
                        $service->postToMember($record, $member);
                        Notification::make()->title(__('Posted to :name', ['name' => $member->name]))->success()->send();
                    }),
                Action::make('clearMatch')
                    ->label('Clear / Match')
                    ->icon('heroicon-o-link')
                    ->color('primary')
                    ->requiresConfirmation()
                    ->modalDescription(__('Match this uncleared transaction against an imported bank transaction to clear both.'))
                    ->hidden(fn ($record) => $record->is_cleared || ! $record->fund_posting_id)
                    ->form([
                        Select::make('imported_transaction_id')
                            ->label('Match with imported transaction')
                            ->options(function ($record) {
                                return BankTransaction::where('id', '!=', $record->id)
                                    ->where('fund_posting_id', null)
                                    ->where('is_cleared', true)
                                    ->orWhere(function ($q) use ($record) {
                                        $q->where('id', '!=', $record->id)
                                            ->whereNull('fund_posting_id')
                                            ->where('is_cleared', false)
                                            ->whereIn('status', ['imported', 'mirrored', 'posted']);
                                    })
                                    ->orderBy('transaction_date', 'desc')
                                    ->limit(50)
                                    ->get()
                                    ->mapWithKeys(fn ($txn) => [
                                        $txn->id => "{$txn->transaction_date->format('Y-m-d')} | {$txn->description} | \${$txn->amount}",
                                    ]);
                            })
                            ->searchable()
                            ->required(),
                    ])
                    ->action(function ($record, array $data, FundPostingService $service) {
                        $imported = BankTransaction::findOrFail($data['imported_transaction_id']);
                        $service->clearTransaction($record, $imported);
                        Notification::make()->title(__('Transactions matched and cleared'))->success()->send();
                    }),
                Action::make('ignore')
                    ->label('Ignore')
                    ->icon('heroicon-o-x-mark')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->hidden(fn ($record) => $record->status !== 'imported')
                    ->action(function ($record) {
                        $record->update(['status' => 'ignored']);
                        Notification::make()->title(__('Transaction ignored'))->send();
                    }),
            ])
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('mirrorSelectedToCash')
                        ->label('Mirror to cash')
                        ->icon('heroicon-o-arrow-right')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalDescription(__('Mirror all selected imported transactions to the Master Cash account.'))
                        ->action(function (Collection $records, FundFlowService $service) {
                            $importedIds = $records->where('status', 'imported')->pluck('id');
                            if ($importedIds->isEmpty()) {
                                Notification::make()->title(__('No imported transactions selected'))->warning()->send();

                                return;
                            }
                            $count = $service->mirrorToCash($importedIds);
                            Notification::make()->title(__(':count transaction(s) mirrored to cash', ['count' => $count]))->success()->send();
                        }),
                    BulkAction::make('ignoreSelected')
                        ->label('Ignore selected')
                        ->icon('heroicon-o-x-mark')
                        ->color('gray')
                        ->requiresConfirmation()
                        ->action(function (Collection $records) {
                            $count = 0;
                            foreach ($records as $record) {
                                if ($record->status === 'imported') {
                                    $record->update(['status' => 'ignored']);
                                    $count++;
                                }
                            }
                            Notification::make()->title(__(':count transaction(s) ignored', ['count' => $count]))->send();
                        }),
                ]),
            ])
            ->defaultSort('transaction_date', 'desc');
    }
}
