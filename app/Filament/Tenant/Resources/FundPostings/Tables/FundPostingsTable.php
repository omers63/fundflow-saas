<?php

namespace App\Filament\Tenant\Resources\FundPostings\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\ViewActions\ViewFundPostingAction;
use App\Filament\Tenant\Resources\FundPostings\FundPostingResource;
use App\Models\Tenant\Setting;
use App\Services\FundPostingService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Filters\TernaryFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

class FundPostingsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            ViewFundPostingAction::configure($table)
                ->columns([
                    TextColumn::make('member.name')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('posting_date')
                        ->date()
                        ->sortable(),
                    TextColumn::make('amount')
                        ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                        ->sortable(),
                    TextColumn::make('reference')
                        ->placeholder(__('—'))
                        ->searchable(),
                    TextColumn::make('comments')
                        ->limit(30)
                        ->placeholder(__('—')),
                    TextColumn::make('attachment')
                        ->label('Receipt')
                        ->formatStateUsing(fn (?string $state): string => $state ? __('View') : __('—'))
                        ->url(fn ($record): ?string => $record->attachment
                            ? ViewFundPostingAction::attachmentUrl($record->attachment)
                            : null)
                        ->openUrlInNewTab()
                        ->color('primary'),
                    TextColumn::make('status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'pending' => 'warning',
                            'accepted' => 'success',
                            'rejected' => 'danger',
                        }),
                    TextColumn::make('created_at')
                        ->label('Submitted')
                        ->dateTime()
                        ->sortable(),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options([
                            'pending' => 'Pending',
                            'accepted' => 'Accepted',
                            'rejected' => 'Rejected',
                        ])
                        ->default('pending'),
                    SelectFilter::make('member_id')
                        ->label('Member')
                        ->relationship('member', 'name')
                        ->searchable()
                        ->preload(),
                    DateColumnRangeFilter::make('posting_date', 'Posting date'),
                    DateColumnRangeFilter::make('created_at', 'Submitted'),
                    TernaryFilter::make('attachment')
                        ->label('Receipt')
                        ->nullable()
                        ->trueLabel(__('Has receipt'))
                        ->falseLabel(__('No receipt')),
                ])
                ->recordActions(TableRecordActionGroups::wrap([
                    ViewFundPostingAction::make(),
                    Action::make('accept')
                        ->label('Accept')
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading(__('Accept deposit'))
                        ->modalWidth('2xl')
                        ->fillForm(ViewFundPostingAction::fillFormFromRecord())
                        ->hidden(fn ($record) => $record->status !== 'pending')
                        ->schema(fn (): array => ViewFundPostingAction::modalSchemaWith([
                            Textarea::make('admin_remarks')
                                ->label(__('Remarks (optional)'))
                                ->rows(2),
                        ]))
                        ->action(function ($record, array $data, FundPostingService $service, Component $livewire) {
                            $service->accept($record, auth()->id(), $data['admin_remarks'] ?? null);
                            Notification::make()->title(__('Deposit accepted'))->success()->send();

                            FundPostingResource::dispatchInsightsRefresh($livewire);
                        }),
                    Action::make('reject')
                        ->label('Reject')
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(__('Reject deposit'))
                        ->modalWidth('2xl')
                        ->fillForm(ViewFundPostingAction::fillFormFromRecord())
                        ->hidden(fn ($record) => $record->status !== 'pending')
                        ->schema(fn (): array => ViewFundPostingAction::modalSchemaWith([
                            Textarea::make('admin_remarks')
                                ->label(__('Reason for rejection'))
                                ->required()
                                ->rows(2),
                        ]))
                        ->action(function ($record, array $data, FundPostingService $service, Component $livewire) {
                            $service->reject($record, auth()->id(), $data['admin_remarks']);
                            Notification::make()->title(__('Deposit rejected'))->send();

                            FundPostingResource::dispatchInsightsRefresh($livewire);
                        }),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        BulkAction::make('acceptSelected')
                            ->label('Accept selected')
                            ->icon('heroicon-o-check-circle')
                            ->color('success')
                            ->requiresConfirmation()
                            ->action(function (Collection $records, FundPostingService $service, Component $livewire) {
                                $count = 0;
                                foreach ($records as $record) {
                                    if ($record->status === 'pending') {
                                        $service->accept($record, auth()->id());
                                        $count++;
                                    }
                                }
                                Notification::make()->title(__(':count posting(s) accepted', ['count' => $count]))->success()->send();

                                FundPostingResource::dispatchInsightsRefresh($livewire);
                            }),
                        BulkAction::make('rejectSelected')
                            ->label(__('Reject selected'))
                            ->icon('heroicon-o-x-circle')
                            ->color('danger')
                            ->requiresConfirmation()
                            ->modalHeading(__('Reject deposits'))
                            ->form([
                                Textarea::make('admin_remarks')
                                    ->label(__('Reason for rejection'))
                                    ->required()
                                    ->rows(2),
                            ])
                            ->action(function (Collection $records, array $data, FundPostingService $service, Component $livewire) {
                                $count = 0;
                                foreach ($records as $record) {
                                    if ($record->status === 'pending') {
                                        $service->reject($record, auth()->id(), $data['admin_remarks']);
                                        $count++;
                                    }
                                }
                                Notification::make()->title(__(':count posting(s) rejected', ['count' => $count]))->send();

                                FundPostingResource::dispatchInsightsRefresh($livewire);
                            }),
                    ]),
                ])
                ->defaultSort('created_at', 'desc'),
            TableGrouping::fundPostings()
        );
    }
}
