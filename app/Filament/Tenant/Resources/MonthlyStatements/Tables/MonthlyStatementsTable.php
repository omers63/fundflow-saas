<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MonthlyStatements\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberSelect;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\MonthlyStatements\MonthlyStatementResource;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\Setting;
use App\Notifications\Tenant\MonthlyStatementNotification;
use App\Services\MonthlyStatementService;
use App\Support\BusinessDay;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\ForceDeleteBulkAction;
use Filament\Actions\RestoreAction;
use Filament\Actions\RestoreBulkAction;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

class MonthlyStatementsTable
{
    public static function configure(Table $table): Table
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return TableGrouping::apply($table
            ->columns([
                MemberTableColumns::relationNumber(),
                MemberTableColumns::relationName(label: __('Member'))
                    ->searchable()
                    ->sortable(),
                TextColumn::make('period')
                    ->sortable(),
                TextColumn::make('opening_balance')
                    ->label(__('Opening fund'))
                    ->money($currency),
                TextColumn::make('total_contributions')
                    ->label(__('Contributions'))
                    ->money($currency),
                TextColumn::make('total_repayments')
                    ->label(__('Repayments'))
                    ->money($currency),
                TextColumn::make('closing_balance')
                    ->label(__('Closing fund'))
                    ->money($currency)
                    ->weight('bold'),
                TextColumn::make('generated_at')
                    ->label(__('Generated'))
                    ->dateTime('d M Y')
                    ->sortable(),
                IconColumn::make('notified_at')
                    ->label(__('Sent'))
                    ->boolean()
                    ->trueIcon('heroicon-o-check-circle')
                    ->falseIcon('heroicon-o-clock')
                    ->trueColor('success')
                    ->falseColor('warning')
                    ->getStateUsing(fn (MonthlyStatement $record): bool => $record->notified_at !== null),
                TextColumn::make('notified_at')
                    ->dateTime()
                    ->placeholder(__('—'))
                    ->toggleable(isToggledHiddenByDefault: true),
            ])
            ->filters([
                MemberSelect::filter('member_id'),
                Filter::make('period')
                    ->schema([
                        TextInput::make('period')->placeholder(__('YYYY-MM')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['period'] ?? null)
                        ? $query->where('period', $data['period'])
                        : $query)
                    ->indicateUsing(fn (array $data): ?string => filled($data['period'] ?? null)
                        ? __('Period').': '.$data['period']
                        : null),
                SelectFilter::make('period_year')
                    ->label(__('Year'))
                    ->options(array_combine(
                        range((int) BusinessDay::today()->year, (int) BusinessDay::today()->year - 15),
                        range((int) BusinessDay::today()->year, (int) BusinessDay::today()->year - 15),
                    ))
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('period', 'like', $data['value'].'-%')
                        : $query),
                TernaryFilter::make('notified')
                    ->label(__('Sent'))
                    ->placeholder(__('All'))
                    ->trueLabel(__('Sent'))
                    ->falseLabel(__('Unsent'))
                    ->queries(
                        true: fn (Builder $query): Builder => $query->whereNotNull('notified_at'),
                        false: fn (Builder $query): Builder => $query->whereNull('notified_at'),
                    ),
                DateColumnRangeFilter::make('generated_at', __('Generated')),
                TrashedFilter::make(),
            ])
            ->defaultSort('period', 'desc')
            ->recordActions(TableRecordActionGroups::wrap([
                Action::make('regenerate')
                    ->label(__('Regenerate'))
                    ->icon('heroicon-o-arrow-path')
                    ->color('warning')
                    ->requiresConfirmation()
                    ->modalDescription(__('This will recalculate all financial figures for this statement. Existing data will be overwritten.'))
                    ->action(function (MonthlyStatement $record, Component $livewire): void {
                        app(MonthlyStatementService::class)->generateForMember(
                            $record->member,
                            $record->period,
                            false,
                        );
                        Notification::make()->title(__('Statement regenerated'))->success()->send();

                        MonthlyStatementResource::dispatchInsightsRefresh($livewire);
                    }),
                Action::make('pdf')
                    ->label(__('Download'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (MonthlyStatement $record): string => route('tenant.admin.statement.pdf', $record))
                    ->openUrlInNewTab(),
                Action::make('notify')
                    ->label(__('Notify'))
                    ->icon('heroicon-o-bell')
                    ->color('gray')
                    ->requiresConfirmation()
                    ->modalHeading(fn (MonthlyStatement $record): string => __('Notify :name', ['name' => $record->member->name]))
                    ->modalDescription(fn (MonthlyStatement $record): string => __('This will notify the member about the statement PDF for :period.', [
                        'period' => $record->period_formatted,
                    ]))
                    ->action(function (MonthlyStatement $record, Component $livewire): void {
                        $sent = app(MonthlyStatementService::class)->sendNotification(
                            $record,
                            MonthlyStatementNotification::DELIVERY_NOTIFY,
                        );

                        Notification::make()
                            ->title($sent
                                ? __('Statement sent to :name', ['name' => $record->member->name])
                                : __('No notification channels available for :name', ['name' => $record->member->name]))
                            ->{$sent ? 'success' : 'warning'}()
                            ->send();

                        MonthlyStatementResource::dispatchInsightsRefresh($livewire);
                    }),
                Action::make('email')
                    ->label(__('Email'))
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading(fn (MonthlyStatement $record): string => __('Email statement to :name', ['name' => $record->member->name]))
                    ->modalDescription(fn (MonthlyStatement $record): string => __('This will email the member about the statement PDF for :period.', [
                        'period' => $record->period_formatted,
                    ]))
                    ->action(function (MonthlyStatement $record, Component $livewire): void {
                        $sent = app(MonthlyStatementService::class)->sendNotification(
                            $record,
                            MonthlyStatementNotification::DELIVERY_EMAIL,
                        );

                        Notification::make()
                            ->title($sent
                                ? __('Statement sent to :name', ['name' => $record->member->name])
                                : __('No email channel available for :name', ['name' => $record->member->name]))
                            ->{$sent ? 'success' : 'warning'}()
                            ->send();

                        MonthlyStatementResource::dispatchInsightsRefresh($livewire);
                    }),
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('notifySelected')
                        ->label(__('Notify'))
                        ->icon('heroicon-o-bell')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading(__('Notify selected statements'))
                        ->modalDescription(__('Sends in-app notifications for the selected statements.'))
                        ->action(function (Collection $records, Component $livewire): void {
                            self::bulkDeliver(
                                $records,
                                $livewire,
                                MonthlyStatementNotification::DELIVERY_NOTIFY,
                                'Notified :sent · skipped :skipped',
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('emailSelected')
                        ->label(__('Email'))
                        ->icon('heroicon-o-envelope')
                        ->color('info')
                        ->requiresConfirmation()
                        ->modalHeading(__('Email selected statements'))
                        ->modalDescription(__('Emails the selected statements to members.'))
                        ->action(function (Collection $records, Component $livewire): void {
                            self::bulkDeliver(
                                $records,
                                $livewire,
                                MonthlyStatementNotification::DELIVERY_EMAIL,
                                'Emailed :sent · skipped :skipped',
                            );
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('regenerateSelected')
                        ->label(__('Regenerate'))
                        ->icon('heroicon-o-arrow-path')
                        ->color('warning')
                        ->requiresConfirmation()
                        ->modalHeading(__('Regenerate selected statements'))
                        ->modalDescription(__('Recalculates financial figures for each selected statement. Delivery status is reset.'))
                        ->action(function (Collection $records, Component $livewire): void {
                            $svc = app(MonthlyStatementService::class);
                            $done = 0;
                            $failed = 0;

                            $records->loadMissing('member');

                            foreach ($records as $record) {
                                if (! $record instanceof MonthlyStatement || $record->member === null) {
                                    $failed++;

                                    continue;
                                }

                                try {
                                    $svc->generateForMember($record->member, $record->period, false);
                                    $done++;
                                } catch (\Throwable $exception) {
                                    $failed++;
                                    report($exception);
                                }
                            }

                            Notification::make()
                                ->title(__('Regenerated :done · failed :failed', [
                                    'done' => $done,
                                    'failed' => $failed,
                                ]))
                                ->color($failed > 0 ? 'warning' : 'success')
                                ->send();

                            MonthlyStatementResource::dispatchInsightsRefresh($livewire);
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                    RestoreBulkAction::make(),
                    ForceDeleteBulkAction::make(),
                    TableToolbar::refreshBulkAction(),
                ]),
            ]), TableGrouping::monthlyStatements());
    }

    /**
     * @param  Collection<int, MonthlyStatement>  $records
     */
    private static function bulkDeliver(
        Collection $records,
        Component $livewire,
        string $delivery,
        string $resultMessageKey,
    ): void {
        $svc = app(MonthlyStatementService::class);
        $sent = 0;
        $skipped = 0;

        foreach ($records as $record) {
            if (! $record instanceof MonthlyStatement) {
                $skipped++;

                continue;
            }

            if ($svc->sendNotification($record, $delivery)) {
                $sent++;

                continue;
            }

            $skipped++;
        }

        Notification::make()
            ->title(__($resultMessageKey, ['sent' => $sent, 'skipped' => $skipped]))
            ->color($sent > 0 ? 'success' : 'warning')
            ->send();

        MonthlyStatementResource::dispatchInsightsRefresh($livewire);
    }
}
