<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MasterAccounts\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\BankClearingQueueActions;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Filament\Tenant\Support\ViewBankTransactionAction;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Setting;
use App\Services\BankClearingMatchService;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class BankLinesAwaitingPostingRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'bankLinesAwaitingPosting';

    protected static ?string $title = 'Bank lines awaiting posting';

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return $ownerRecord instanceof Account
            && $ownerRecord->is_master
            && $ownerRecord->type === 'cash';
    }

    public function table(Table $table): Table
    {
        $count = app(BankClearingMatchService::class)->bankLinesAwaitingPostingCount();

        return TableGrouping::apply(
            $table
                ->columns([
                    TextColumn::make('transaction_date')
                        ->date()
                        ->sortable(),
                    TextColumn::make('bankStatement.filename')
                        ->label(__('Source'))
                        ->limit(20),
                    TextColumn::make('amount')
                        ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                        ->sortable()
                        ->color(fn ($state): string => $state >= 0 ? 'success' : 'danger'),
                    TextColumn::make('member.name')
                        ->label(__('Member'))
                        ->placeholder(__('Unassigned'))
                        ->sortable(),
                    TextColumn::make('description')
                        ->searchable()
                        ->wrap(),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => BankTransaction::statusOptions()[$state] ?? $state)
                        ->color(fn (string $state): string => match ($state) {
                            'imported' => 'warning',
                            'mirrored' => 'info',
                            default => 'gray',
                        }),
                ])
                ->filters([
                    DateColumnRangeFilter::make('transaction_date', __('Transaction date')),
                ])
                ->description($count > 0
                    ? __(':count open — post or match from the bank clearing work queue.', ['count' => $count])
                    : __('No imported lines awaiting posting.'))
                ->headerActions([
                    BankClearingQueueActions::openInBankClearingAction(BankClearingTabRegistry::FILTER_BANK_FILE),
                ])
                ->paginated([5, 10, 25])
                ->defaultPaginationPageOption(5)
                ->recordUrl(fn (): ?string => null)
                ->recordAction(ViewAction::getDefaultName())
                ->emptyStateDescription(__('Imported bank statement lines that still need posting to the master cash pool.'))
                ->recordActions(TableRecordActionGroups::wrap([
                    ViewBankTransactionAction::make(),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ])
                ->defaultSort('transaction_date', 'desc'),
            TableGrouping::bankTransactions()
        );
    }
}
