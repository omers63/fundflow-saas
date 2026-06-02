<?php

namespace App\Filament\Tenant\Resources\Contributions\Tables;

use App\Filament\Support\ContributionListTableHeaderActions;
use App\Filament\Support\ContributionTableActions;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use App\Models\Tenant\Setting;
use App\Services\ContributionService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

class ContributionsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            $table
                ->headerActions(ContributionListTableHeaderActions::ledgerActions())
                ->columns([
                    TextColumn::make('member.name')
                        ->searchable()
                        ->sortable(),
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
                            'failed' => 'danger',
                        }),
                    TextColumn::make('posted_at')
                        ->dateTime()
                        ->placeholder(__('Not posted')),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options([
                            'pending' => 'Pending',
                            'posted' => 'Posted',
                            'failed' => 'Failed',
                        ]),
                    SelectFilter::make('member_id')
                        ->label('Member')
                        ->relationship('member', 'name')
                        ->searchable()
                        ->preload(),
                    DateColumnRangeFilter::make('period', 'Contribution period'),
                    DateColumnRangeFilter::make('posted_at', 'Posted'),
                ])
                ->recordActions(TableRecordActionGroups::wrap([
                    ContributionTableActions::delete(),
                    Action::make('post')
                        ->label('Post')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->hidden(fn ($record) => $record->status !== 'pending')
                        ->action(function ($record, ContributionService $service, Component $livewire) {
                            $service->postContribution($record);
                            Notification::make()
                                ->title(__('Contribution posted successfully'))
                                ->success()
                                ->send();

                            ContributionResource::dispatchInsightsRefresh($livewire);
                        }),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        ContributionTableActions::deleteBulk(),
                        BulkAction::make('postSelected')
                            ->label('Post selected')
                            ->icon('heroicon-o-check-circle')
                            ->color('success')
                            ->requiresConfirmation()
                            ->action(function (Collection $records, ContributionService $service, Component $livewire) {
                                $posted = 0;
                                foreach ($records as $record) {
                                    if ($record->status === 'pending') {
                                        $service->postContribution($record);
                                        $posted++;
                                    }
                                }
                                Notification::make()
                                    ->title(__(':count contribution(s) posted', ['count' => $posted]))
                                    ->success()
                                    ->send();

                                ContributionResource::dispatchInsightsRefresh($livewire);
                            }),
                        TableToolbar::refreshBulkAction(),
                    ]),
                ])
                ->defaultSort('period', 'desc'),
            TableGrouping::contributions()
        );
    }
}
