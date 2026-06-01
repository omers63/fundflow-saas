<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MonthlyStatements\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\MonthlyStatements\MonthlyStatementResource;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\Setting;
use App\Services\MonthlyStatementService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Livewire\Component;

class MonthlyStatementsTable
{
    public static function configure(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->columns([
                MemberTableColumns::relationNumber()
                    ->sortable(),
                MemberTableColumns::relationName(label: __('Member'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('period')
                    ->label(__('Period'))
                    ->sortable(),
                TextColumn::make('total_contributions')
                    ->label(__('Contributions'))
                    ->money($currency),
                TextColumn::make('total_repayments')
                    ->label(__('Repayments'))
                    ->money($currency),
                TextColumn::make('closing_balance')
                    ->label(__('Closing'))
                    ->money($currency),
                TextColumn::make('generated_at')
                    ->dateTime()
                    ->sortable(),
                TextColumn::make('notified_at')
                    ->dateTime()
                    ->placeholder(__('—')),
            ])
            ->filters([
                DateColumnRangeFilter::make('generated_at', __('Generated')),
                DateColumnRangeFilter::make('notified_at', __('Notified')),
            ])
            ->defaultSort('period', 'desc')
            ->recordActions(TableRecordActionGroups::wrap([
                Action::make('pdf')
                    ->label(__('Download PDF'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn(MonthlyStatement $record): string => route('tenant.admin.statement.pdf', $record))
                    ->openUrlInNewTab(),
                Action::make('regenerate')
                    ->label(__('Regenerate'))
                    ->icon('heroicon-o-arrow-path')
                    ->requiresConfirmation()
                    ->action(function (MonthlyStatement $record, Component $livewire): void {
                        app(MonthlyStatementService::class)->generateForMember(
                            $record->member,
                            $record->period,
                            false,
                        );
                        Notification::make()->title(__('Statement regenerated'))->success()->send();

                        MonthlyStatementResource::dispatchInsightsRefresh($livewire);
                    }),
                Action::make('notify')
                    ->label(__('Send notification'))
                    ->icon('heroicon-o-paper-airplane')
                    ->action(function (MonthlyStatement $record, Component $livewire): void {
                        app(MonthlyStatementService::class)->sendNotification($record);
                        Notification::make()->title(__('Notification sent'))->success()->send();

                        MonthlyStatementResource::dispatchInsightsRefresh($livewire);
                    }),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('generate')
                        ->label(__('Generate for period'))
                        ->icon('heroicon-o-document-plus')
                        ->schema([
                            TextInput::make('period')
                                ->label(__('Period (YYYY-MM)'))
                                ->placeholder(now()->subMonthNoOverflow()->format('Y-m'))
                                ->required(),
                        ])
                        ->action(function (array $data, Component $livewire): void {
                            $count = app(MonthlyStatementService::class)
                                ->generateForAllMembers($data['period'], false);
                            Notification::make()
                                ->title(__(':count statement(s) generated', ['count' => $count]))
                                ->success()
                                ->send();

                            MonthlyStatementResource::dispatchInsightsRefresh($livewire);
                        }),
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::monthlyStatements());
    }
}
