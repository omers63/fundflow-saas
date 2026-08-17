<?php

namespace App\Filament\Tenant\Resources\CashOutRequests\Tables;

use App\Filament\Support\ActionModalFailure;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberFilamentActions;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableHeaderIconAction;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\CashOutRequests\CashOutRequestResource;
use App\Filament\Tenant\Resources\CashOutRequests\Schemas\CashOutRequestForm;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\MemberCashOutService;
use App\Support\OperationalRequestStatus;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Livewire\Component;

class CashOutRequestsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            $table
                ->headerActions([
                    TableHeaderIconAction::apply(
                        Action::make('create')
                            ->label(__('New cash out'))
                            ->icon('heroicon-o-plus-circle')
                            ->modalHeading(__('New cash out'))
                            ->modalDescription(__('Creates and approves a cash-out on the selected date. Member and master cash are debited, and an uncleared bank line is added for remittance.'))
                            ->modalWidth('2xl')
                            ->schema(CashOutRequestForm::components())
                            ->action(function (array $data, Action $action, MemberCashOutService $service, Component $livewire): void {
                                if (
                                    ! ActionModalFailure::attemptThrowable(
                                        $action,
                                        function () use ($data, $service): void {
                                            $member = Member::query()->findOrFail($data['member_id']);
                                            $notes = filled($data['notes'] ?? null) ? (string) $data['notes'] : null;
                                            $transactedAt = MemberFilamentActions::resolveCashOutDate($data['cash_out_date'] ?? null);

                                            $request = $service->submit(
                                                member: $member,
                                                amount: (float) $data['amount'],
                                                notes: $notes,
                                            );

                                            $service->accept(
                                                $request,
                                                auth('tenant')->id(),
                                                $notes,
                                                $transactedAt,
                                            );
                                        },
                                        __('Could not create cash out'),
                                    )
                                ) {
                                    return;
                                }

                                Notification::make()
                                    ->title(__('Cash out approved'))
                                    ->body(__('Member and master cash have been debited. Complete the remittance checklist under Audit & System, then clear the bank line when the statement imports.'))
                                    ->success()
                                    ->send();

                                CashOutRequestResource::dispatchInsightsRefresh($livewire);
                            }),
                    ),
                ])
                ->columns([
                    TextColumn::make('id')
                        ->label(__('Request #'))
                        ->sortable()
                        ->searchable(),
                    MemberTableColumns::relationNumber(),
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
                        ->color(fn (string $state): string => OperationalRequestStatus::color($state)),
                    TextColumn::make('created_at')
                        ->label(__('Submitted'))
                        ->dateTime()
                        ->sortable(),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options(OperationalRequestStatus::options()),
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
                        ->modalDescription(__('Debits the member and master cash accounts, adds a remittance checklist item, and creates a pending bank line. Transfer money, then mark remittance completed; clear the bank line when the statement imports.'))
                        ->hidden(fn ($record) => $record->status !== 'pending')
                        ->schema([
                            Textarea::make('admin_remarks')
                                ->label(__('Remarks (optional)'))
                                ->rows(2),
                        ])
                        ->action(function ($record, array $data, Action $action, MemberCashOutService $service, Component $livewire): void {
                            if (! ActionModalFailure::attemptThrowable(
                                $action,
                                fn () => $service->accept($record, auth()->id(), $data['admin_remarks'] ?? null),
                                __('Could not accept cash out'),
                            )) {
                                return;
                            }

                            Notification::make()->title(__('Cash out accepted'))->success()->send();

                            CashOutRequestResource::dispatchInsightsRefresh($livewire);
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
                        ->action(function ($record, array $data, Action $action, MemberCashOutService $service, Component $livewire): void {
                            if (! ActionModalFailure::attemptThrowable(
                                $action,
                                fn () => $service->reject($record, auth()->id(), $data['admin_remarks']),
                                __('Could not reject cash out'),
                            )) {
                                return;
                            }

                            Notification::make()->title(__('Cash out rejected'))->send();

                            CashOutRequestResource::dispatchInsightsRefresh($livewire);
                        }),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        BulkAction::make('acceptSelected')
                            ->label(__('Accept selected'))
                            ->icon('heroicon-o-check-circle')
                            ->color('success')
                            ->requiresConfirmation()
                            ->action(function (BulkAction $action, Collection $records, MemberCashOutService $service, Component $livewire): void {
                                $count = 0;
                                foreach ($records as $record) {
                                    if ($record->status !== 'pending') {
                                        continue;
                                    }

                                    if (! ActionModalFailure::attemptThrowable(
                                        $action,
                                        fn () => $service->accept($record, auth()->id()),
                                        __('Could not accept cash out'),
                                    )) {
                                        return;
                                    }

                                    $count++;
                                }

                                Notification::make()->title(__(':count cash out(s) accepted', ['count' => $count]))->success()->send();

                                CashOutRequestResource::dispatchInsightsRefresh($livewire);
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
                            ->action(function (Collection $records, array $data, MemberCashOutService $service, Component $livewire): void {
                                $count = 0;
                                foreach ($records as $record) {
                                    if ($record->status === 'pending') {
                                        $service->reject($record, auth()->id(), $data['admin_remarks']);
                                        $count++;
                                    }
                                }
                                Notification::make()->title(__(':count cash out(s) rejected', ['count' => $count]))->send();

                                CashOutRequestResource::dispatchInsightsRefresh($livewire);
                            }),
                        TableToolbar::refreshBulkAction(),
                    ]),
                ])
                ->defaultSort('created_at', 'desc'),
            TableGrouping::fundPostings()
        );
    }
}
