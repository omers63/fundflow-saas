<?php

namespace App\Filament\Tenant\Resources\BankAccounts\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\BankTransactionTableActions;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Support\ViewActions\ViewBankTransactionAction;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Setting;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class BankTransactionsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'transactions';

    protected static ?string $title = 'Bank Transactions';

    public function table(Table $table): Table
    {
        return ViewBankTransactionAction::configure(
            $table
                ->recordTitleAttribute('description')
                ->columns([
                    TextColumn::make('transaction_date')
                        ->date()
                        ->sortable(),
                    TextColumn::make('description')
                        ->searchable()
                        ->limit(50),
                    TextColumn::make('amount')
                        ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                        ->sortable()
                        ->color(fn ($state): string => $state >= 0 ? 'success' : 'danger'),
                    TextColumn::make('reference')
                        ->placeholder(__('—')),
                    TextColumn::make('member.name')
                        ->placeholder(__('Unassigned')),
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
                ->defaultSort('transaction_date', 'desc'),
        )
            ->recordActions(TableRecordActionGroups::wrap([
                ViewBankTransactionAction::make(),
                BankTransactionTableActions::delete(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    BankTransactionTableActions::deleteBulk(),
                    TableToolbar::refreshBulkAction(),
                ]),
            ]);
    }
}
