<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\Loans\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Setting;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;

class DisbursementsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'disbursements';

    protected static ?string $title = 'Disbursement history';

    public function table(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->columnManager(true)
            ->columns([
                TextColumn::make('disbursed_at')
                    ->label(__('Disbursed at'))
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('amount')
                    ->money($currency)
                    ->sortable(),
                TextColumn::make('master_portion')
                    ->label(__('Master portion'))
                    ->money($currency),
                TextColumn::make('member_portion')
                    ->label(__('Member portion'))
                    ->money($currency),
                TextColumn::make('disbursedBy.name')
                    ->label(__('Posted by'))
                    ->placeholder(__('—')),
                TextColumn::make('notes')
                    ->placeholder(__('—'))
                    ->wrap()
                    ->limit(60),
            ])
            ->defaultSort('disbursed_at', 'desc')
            ->recordActions(TableRecordActionGroups::wrap([]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->emptyStateHeading(__('No disbursements yet'))
            ->emptyStateDescription(__('Partial disbursements appear here until the approved amount is fully posted to the ledger.')), TableGrouping::loanDisbursements());
    }
}
