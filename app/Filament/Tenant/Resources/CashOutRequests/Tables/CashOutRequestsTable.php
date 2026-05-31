<?php

namespace App\Filament\Tenant\Resources\CashOutRequests\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Setting;
use App\Services\MemberCashOutService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class CashOutRequestsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            $table
                ->columns([
                    TextColumn::make('member.name')
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('amount')
                        ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                        ->sortable(),
                    TextColumn::make('notes')
                        ->limit(30)
                        ->placeholder(__('—')),
                    TextColumn::make('status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'pending' => 'warning',
                            'accepted' => 'success',
                            'rejected' => 'danger',
                        }),
                    TextColumn::make('created_at')
                        ->label(__('Submitted'))
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
                        ->label(__('Member'))
                        ->relationship('member', 'name')
                        ->searchable()
                        ->preload(),
                    DateColumnRangeFilter::make('created_at', 'Submitted'),
                ])
                ->recordActions(TableRecordActionGroups::wrap([
                    Action::make('accept')
                        ->label(__('Accept'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading(__('Accept cash out'))
                        ->modalDescription(__('Debits the member and master cash accounts. Match the pending bank line to a statement import later to clear it.'))
                        ->hidden(fn ($record) => $record->status !== 'pending')
                        ->schema([
                            Textarea::make('admin_remarks')
                                ->label(__('Remarks (optional)'))
                                ->rows(2),
                        ])
                        ->action(function ($record, array $data, MemberCashOutService $service): void {
                            $service->accept($record, auth()->id(), $data['admin_remarks'] ?? null);
                            Notification::make()->title(__('Cash out accepted'))->success()->send();
                        }),
                    Action::make('reject')
                        ->label(__('Reject'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(__('Reject cash out'))
                        ->hidden(fn ($record) => $record->status !== 'pending')
                        ->schema([
                            Textarea::make('admin_remarks')
                                ->label(__('Reason for rejection'))
                                ->required()
                                ->rows(2),
                        ])
                        ->action(function ($record, array $data, MemberCashOutService $service): void {
                            $service->reject($record, auth()->id(), $data['admin_remarks']);
                            Notification::make()->title(__('Cash out rejected'))->send();
                        }),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        BulkAction::make('acceptSelected')
                            ->label(__('Accept selected'))
                            ->icon('heroicon-o-check-circle')
                            ->color('success')
                            ->requiresConfirmation()
                            ->action(function (Collection $records, MemberCashOutService $service): void {
                                $count = 0;
                                foreach ($records as $record) {
                                    if ($record->status === 'pending') {
                                        $service->accept($record, auth()->id());
                                        $count++;
                                    }
                                }
                                Notification::make()->title(__(':count cash out(s) accepted', ['count' => $count]))->success()->send();
                            }),
                        BulkAction::make('rejectSelected')
                            ->label(__('Reject selected'))
                            ->icon('heroicon-o-x-circle')
                            ->color('danger')
                            ->requiresConfirmation()
                            ->modalHeading(__('Reject cash outs'))
                            ->form([
                                Textarea::make('admin_remarks')
                                    ->label(__('Reason for rejection'))
                                    ->required()
                                    ->rows(2),
                            ])
                            ->action(function (Collection $records, array $data, MemberCashOutService $service): void {
                                $count = 0;
                                foreach ($records as $record) {
                                    if ($record->status === 'pending') {
                                        $service->reject($record, auth()->id(), $data['admin_remarks']);
                                        $count++;
                                    }
                                }
                                Notification::make()->title(__(':count cash out(s) rejected', ['count' => $count]))->send();
                            }),
                        TableToolbar::refreshBulkAction(),
                    ]),
                ])
                ->defaultSort('created_at', 'desc'),
            TableGrouping::fundPostings()
        );
    }
}
