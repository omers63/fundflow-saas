<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\FundOutRequests\Tables;

use App\Filament\Support\ActionModalFailure;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberFilamentActions;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableHeaderIconAction;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\FundOutRequests\FundOutRequestResource;
use App\Filament\Tenant\Resources\FundOutRequests\Schemas\FundOutRequestForm;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\MemberFundOutService;
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

class FundOutRequestsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            $table
                ->headerActions([
                    TableHeaderIconAction::apply(
                        Action::make('create')
                            ->label(__('New fund out'))
                            ->icon('heroicon-o-plus-circle')
                            ->modalHeading(__('New fund out'))
                            ->modalDescription(__('Creates and approves a fund-out on the selected date. Moves fund balance to cash with master mirrors. No bank remittance is created.'))
                            ->modalWidth('2xl')
                            ->schema(FundOutRequestForm::components())
                            ->action(function (array $data, Action $action, MemberFundOutService $service, Component $livewire): void {
                                if (
                                    ! ActionModalFailure::attemptThrowable(
                                        $action,
                                        function () use ($data, $service): void {
                                            $member = Member::query()->findOrFail($data['member_id']);
                                            $notes = filled($data['notes'] ?? null) ? (string) $data['notes'] : null;
                                            $transactedAt = MemberFilamentActions::resolveCashOutDate($data['fund_out_date'] ?? null);

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
                                        __('Could not create fund out'),
                                    )
                                ) {
                                    return;
                                }

                                Notification::make()
                                    ->title(__('Fund out approved'))
                                    ->body(__('The amount was moved from the member’s fund account to cash (with master mirrors). No bank remittance is created — use cash out if money must leave the bank.'))
                                    ->success()
                                    ->send();

                                FundOutRequestResource::dispatchInsightsRefresh($livewire);
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
                        ->modalHeading(__('Accept fund out'))
                        ->modalDescription(__('Moves the amount from the member’s fund account to their cash account (with master mirrors). No bank payout is created.'))
                        ->hidden(fn ($record) => $record->status !== 'pending')
                        ->schema([
                            Textarea::make('admin_remarks')
                                ->label(__('Remarks (optional)'))
                                ->rows(2),
                        ])
                        ->action(function ($record, array $data, Action $action, MemberFundOutService $service, Component $livewire): void {
                            if (! ActionModalFailure::attemptThrowable(
                                $action,
                                fn () => $service->accept($record, auth()->id(), $data['admin_remarks'] ?? null),
                                __('Could not accept fund out'),
                            )) {
                                return;
                            }

                            Notification::make()->title(__('Fund out accepted'))->success()->send();

                            FundOutRequestResource::dispatchInsightsRefresh($livewire);
                        }),
                    Action::make('reject')
                        ->label(__('Reject'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(__('Reject fund out'))
                        ->hidden(fn ($record) => $record->status !== 'pending')
                        ->schema([
                            Textarea::make('admin_remarks')
                                ->label(__('Reason for rejection'))
                                ->required()
                                ->rows(2),
                        ])
                        ->action(function ($record, array $data, Action $action, MemberFundOutService $service, Component $livewire): void {
                            if (! ActionModalFailure::attemptThrowable(
                                $action,
                                fn () => $service->reject($record, auth()->id(), $data['admin_remarks']),
                                __('Could not reject fund out'),
                            )) {
                                return;
                            }

                            Notification::make()->title(__('Fund out rejected'))->send();

                            FundOutRequestResource::dispatchInsightsRefresh($livewire);
                        }),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        BulkAction::make('acceptSelected')
                            ->label(__('Accept selected'))
                            ->icon('heroicon-o-check-circle')
                            ->color('success')
                            ->requiresConfirmation()
                            ->action(function (BulkAction $action, Collection $records, MemberFundOutService $service, Component $livewire): void {
                                $count = 0;
                                foreach ($records as $record) {
                                    if ($record->status !== 'pending') {
                                        continue;
                                    }

                                    if (! ActionModalFailure::attemptThrowable(
                                        $action,
                                        fn () => $service->accept($record, auth()->id()),
                                        __('Could not accept fund out'),
                                    )) {
                                        return;
                                    }

                                    $count++;
                                }

                                Notification::make()->title(__(':count fund out(s) accepted', ['count' => $count]))->success()->send();

                                FundOutRequestResource::dispatchInsightsRefresh($livewire);
                            }),
                        BulkAction::make('rejectSelected')
                            ->label(__('Reject selected'))
                            ->icon('heroicon-o-x-circle')
                            ->color('danger')
                            ->requiresConfirmation()
                            ->modalHeading(__('Reject fund outs'))
                            ->form([
                                Textarea::make('admin_remarks')
                                    ->label(__('Reason for rejection'))
                                    ->required()
                                    ->rows(2),
                            ])
                            ->action(function (Collection $records, array $data, MemberFundOutService $service, Component $livewire): void {
                                $count = 0;
                                foreach ($records as $record) {
                                    if ($record->status === 'pending') {
                                        $service->reject($record, auth()->id(), $data['admin_remarks']);
                                        $count++;
                                    }
                                }
                                Notification::make()->title(__(':count fund out(s) rejected', ['count' => $count]))->send();

                                FundOutRequestResource::dispatchInsightsRefresh($livewire);
                            }),
                        TableToolbar::refreshBulkAction(),
                    ]),
                ])
                ->defaultSort('created_at', 'desc'),
            TableGrouping::fundPostings()
        );
    }
}
