<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyStatements\Tables;

use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\Setting;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class MyStatementsTable
{
    public static function configure(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->columns([
                TextColumn::make('period')
                    ->label(__('Period'))
                    ->formatStateUsing(fn (MonthlyStatement $record): string => $record->period_formatted)
                    ->sortable(),
                TextColumn::make('total_contributions')
                    ->label(__('Contributions'))
                    ->money($currency),
                TextColumn::make('total_repayments')
                    ->label(__('Repayments'))
                    ->money($currency),
                TextColumn::make('closing_balance')
                    ->label(__('Closing balance'))
                    ->money($currency),
                TextColumn::make('generated_at')
                    ->dateTime(),
            ])
            ->defaultSort('period', 'desc')
            ->recordActions(TableRecordActionGroups::wrap([
                Action::make('download')
                    ->label(__('Download PDF'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (MonthlyStatement $record): string => route('tenant.member.statement.pdf', $record))
                    ->openUrlInNewTab(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::monthlyStatements(includeMember: false));
    }
}
