<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\FundAuditLogs\Tables;

use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Support\ViewFundAuditLogAction;
use App\Models\Tenant\FundAuditLog;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;

class FundAuditLogsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply($table
            ->modifyQueryUsing(fn (Builder $query): Builder => $query->with(['member', 'operator']))
            ->defaultSort('occurred_at', 'desc')
            ->columns([
                TextColumn::make('occurred_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('event_type')
                    ->label(__('Event'))
                    ->searchable()
                    ->wrap(),
                TextColumn::make('domain')
                    ->badge(),
                MemberTableColumns::relationNumber()
                    ->placeholder(__('—'))
                    ->toggleable(),
                TextColumn::make('member.name')
                    ->label(__('Member'))
                    ->placeholder(__('—'))
                    ->toggleable(),
                TextColumn::make('operator.name')
                    ->label(__('Operator'))
                    ->placeholder(__('—'))
                    ->toggleable(),
                TextColumn::make('checksum')
                    ->label(__('Checksum'))
                    ->limit(12)
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                SelectFilter::make('domain')
                    ->options([
                        'reconciliation' => __('Reconciliation'),
                        'migration' => __('Migration'),
                        'ledger' => __('Ledger'),
                        'contribution' => __('Contribution'),
                        'loan' => __('Loan'),
                    ]),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                ViewFundAuditLogAction::make(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    DeleteBulkAction::make()
                        ->authorize(fn (): bool => auth('tenant')->user()?->is_admin === true)
                        ->modalHeading(__('Delete audit log entries'))
                        ->modalDescription(__('Permanently removes the selected audit log rows. This cannot be undone.'))
                        ->using(function (DeleteBulkAction $action, $records): void {
                            foreach ($records as $record) {
                                if (! $record instanceof FundAuditLog) {
                                    continue;
                                }

                                $record->delete();
                            }
                        }),
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::fundAuditLogs());
    }
}
