<?php

namespace App\Filament\Tenant\Resources\Members\RelationManagers;

use App\Filament\Concerns\TranslatesRelationManagerTitle;
use App\Filament\Resources\RelationManagers\RelationManager;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Setting;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\ViewAction;
use Filament\Forms\Components\TextInput;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Support\Carbon;

class ContributionsRelationManager extends RelationManager
{
    use TranslatesRelationManagerTitle;

    protected static string $relationship = 'contributions';

    protected static ?string $title = 'Contributions';

    public function table(Table $table): Table
    {
        return TableGrouping::apply($table
            ->recordTitleAttribute('period')
            ->columns([
                TextColumn::make('period')
                    ->date('M Y')
                    ->sortable(),
                TextColumn::make('amount')
                    ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                    ->sortable(),
                TextColumn::make('status')
                    ->badge()
                    ->color(fn (string $state): string => match ($state) {
                        'pending' => 'warning',
                        'posted' => 'success',
                        default => 'gray',
                    }),
                TextColumn::make('posted_at')
                    ->label('Posted')
                    ->dateTime()
                    ->placeholder(__('—'))
                    ->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')
                    ->options([
                        'pending' => 'Pending',
                        'posted' => 'Posted',
                        'failed' => 'Failed',
                    ]),
                DateColumnRangeFilter::make('period', 'Contribution period'),
                DateColumnRangeFilter::make('posted_at', 'Posted'),
            ])
            ->recordActions(TableRecordActionGroups::wrap([
                ViewAction::make()
                    ->modalHeading(fn (Contribution $record): string => __('Contribution — :period', [
                        'period' => Carbon::parse($record->period)->format('M Y'),
                    ]))
                    ->mutateRecordDataUsing(fn (Contribution $record): array => [
                        'amount_display' => number_format((float) $record->amount, 2),
                        'status_display' => $record->status,
                        'posted_display' => $record->posted_at?->format('Y-m-d H:i') ?? __('—'),
                    ])
                    ->schema([
                        TextInput::make('amount_display')
                            ->label(__('Amount'))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('status_display')
                            ->label(__('Status'))
                            ->disabled()
                            ->dehydrated(false),
                        TextInput::make('posted_display')
                            ->label(__('Posted'))
                            ->disabled()
                            ->dehydrated(false),
                    ]),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    TableToolbar::refreshBulkAction(),
                ]),
            ])
            ->defaultSort('period', 'desc'),
            TableGrouping::contributions(includeMember: false));
    }
}
