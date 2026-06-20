<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MasterAccounts\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\BankTransactionTableActions;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Support\ViewBankTransactionAction;
use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Setting;
use App\Services\FundFlowService;
use App\Support\BankTransactionWorkflow;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
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
                    SelectFilter::make('member_id')
                        ->label(__('Member'))
                        ->relationship('member', 'name')
                        ->searchable()
                        ->preload(),
                ])
                ->recordUrl(fn (): ?string => null)
                ->recordAction(ViewAction::getDefaultName())
                ->emptyStateDescription(__('Imported bank statement lines that still need posting to the master cash pool.'))
                ->recordActions(TableRecordActionGroups::wrap([
                    ViewBankTransactionAction::make(),
                    Action::make('mirrorToCash')
                        ->label(__('Post to cash'))
                        ->icon('heroicon-o-arrow-right')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalDescription(__('Post this statement line to the master cash pool.'))
                        ->hidden(fn (BankTransaction $record): bool => ! BankTransactionWorkflow::canPostToCash($record))
                        ->action(function (BankTransaction $record, FundFlowService $service): void {
                            $service->mirrorToCash([$record->id]);
                            Notification::make()->title(__('Posted to master cash'))->success()->send();
                        }),
                    BankTransactionTableActions::postToMember(),
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
