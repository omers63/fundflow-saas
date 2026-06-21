<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MonthlyStatements\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\MonthlyStatements\MonthlyStatementResource;
use App\Models\Tenant\Member;
use App\Models\Tenant\MonthlyStatement;
use App\Models\Tenant\Setting;
use App\Services\MonthlyStatementService;
use App\Support\BusinessDay;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\EditAction;
use Filament\Actions\ForceDeleteAction;
use Filament\Actions\RestoreAction;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TrashedFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
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
                    ->sortable(),
                TextColumn::make('opening_balance')
                    ->money($currency),
                TextColumn::make('total_contributions')
                    ->label(__('Contributions'))
                    ->money($currency),
                TextColumn::make('total_repayments')
                    ->label(__('Repayments'))
                    ->money($currency),
                TextColumn::make('closing_balance')
                    ->label(__('Closing'))
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
                SelectFilter::make('member_id')
                    ->label(__('Member'))
                    ->searchable()
                    ->options(fn (): array => Member::query()
                        ->orderBy('member_number')
                        ->get()
                        ->mapWithKeys(fn (Member $member): array => [
                            $member->id => "{$member->member_number} — {$member->name}",
                        ])
                        ->all()),
                Filter::make('period')
                    ->schema([
                        TextInput::make('period')->placeholder(__('YYYY-MM')),
                    ])
                    ->query(fn (Builder $query, array $data): Builder => filled($data['period'] ?? null)
                        ? $query->where('period', $data['period'])
                        : $query),
                SelectFilter::make('period_year')
                    ->label(__('Year'))
                    ->options(array_combine(
                        range((int) now()->year, (int) now()->year - 15),
                        range((int) now()->year, (int) now()->year - 15),
                    ))
                    ->query(fn (Builder $query, array $data): Builder => filled($data['value'] ?? null)
                        ? $query->where('period', 'like', $data['value'].'-%')
                        : $query),
                Filter::make('not_notified')
                    ->label(__('Not notified'))
                    ->query(fn (Builder $query): Builder => $query->whereNull('notified_at')),
                DateColumnRangeFilter::make('generated_at', __('Generated')),
                TrashedFilter::make(),
            ])
            ->defaultSort('period', 'desc')
            ->recordActions(TableRecordActionGroups::wrap([
                Action::make('pdf')
                    ->label(__('Download PDF'))
                    ->icon('heroicon-o-arrow-down-tray')
                    ->url(fn (MonthlyStatement $record): string => route('tenant.admin.statement.pdf', $record))
                    ->openUrlInNewTab(),
                Action::make('send_to_member')
                    ->label(__('Send'))
                    ->icon('heroicon-o-envelope')
                    ->color('info')
                    ->requiresConfirmation()
                    ->modalHeading(fn (MonthlyStatement $record): string => __('Send statement to :name', ['name' => $record->member->name]))
                    ->modalDescription(fn (MonthlyStatement $record): string => __('This will notify the member about the statement PDF for :period.', [
                        'period' => $record->period_formatted,
                    ]))
                    ->action(function (MonthlyStatement $record, Component $livewire): void {
                        app(MonthlyStatementService::class)->sendNotification($record);
                        Notification::make()
                            ->title(__('Statement sent to :name', ['name' => $record->member->name]))
                            ->success()
                            ->send();

                        MonthlyStatementResource::dispatchInsightsRefresh($livewire);
                    }),
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
                EditAction::make(),
                DeleteAction::make(),
                RestoreAction::make(),
                ForceDeleteAction::make(),
            ]))
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('generate')
                        ->label(__('Generate for period'))
                        ->icon('heroicon-o-document-plus')
                        ->schema([
                            TextInput::make('period')
                                ->label(__('Period (YYYY-MM)'))
                                ->placeholder(BusinessDay::now()->subMonthNoOverflow()->format('Y-m'))
                                ->required(),
                            Toggle::make('send_notification')
                                ->label(__('Email members after generation'))
                                ->default(false),
                        ])
                        ->action(function (array $data, Component $livewire): void {
                            $count = app(MonthlyStatementService::class)
                                ->generateForAllMembers($data['period'], (bool) ($data['send_notification'] ?? false));
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
