<?php

namespace App\Filament\Tenant\Resources\Members\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberAccountTableActions;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\Accounts\AccountResource;
use App\Models\Tenant\Setting;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;

class AccountsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'accounts';

    protected static ?string $title = 'Accounts';

    public function table(Table $table): Table
    {
        return TableGrouping::apply($table
            ->recordTitleAttribute('name')
            ->columns([
                TextColumn::make('name')
                    ->sortable(),
                TextColumn::make('type')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'cash' => 'info',
                        'fund' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('balance')
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable(),
                TextColumn::make('updated_at')
                    ->label('Last updated')
                    ->dateTime()
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('type')
                    ->options([
                        'cash' => 'Cash',
                        'fund' => 'Fund',
                    ]),
                DateColumnRangeFilter::make('updated_at', 'Last updated'),
            ])
            ->recordUrl(fn (Model $record): string => AccountResource::getUrl('view', ['record' => $record]))
            ->recordActions(TableRecordActionGroups::wrap([
                Action::make('viewAccount')
                    ->label(__('View'))
                    ->icon('heroicon-o-eye')
                    ->url(fn ($record): string => AccountResource::getUrl('view', ['record' => $record])),
                MemberAccountTableActions::delete(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    MemberAccountTableActions::deleteBulk(),
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->defaultSort('type'), TableGrouping::memberAccounts(includeType: true));
    }
}
