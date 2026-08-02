<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MemberCashTransferRequests\Tables;

use App\Filament\Support\ActionModalFailure;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberSelect;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableHeaderIconAction;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\MemberCashTransferRequests\MemberCashTransferRequestResource;
use App\Models\Tenant\MemberCashTransferRequest;
use App\Models\Tenant\Setting;
use App\Services\MemberCashTransferService;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class MemberCashTransferRequestsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            $table
                ->headerActions([
                    TableHeaderIconAction::apply(
                        CreateAction::make()
                            ->label(__('New cash transfer'))
                            ->icon('heroicon-o-plus-circle')
                            ->url(MemberCashTransferRequestResource::getUrl('create')),
                    ),
                ])
                ->columns([
                    TextColumn::make('id')
                        ->label(__('Request #'))
                        ->sortable()
                        ->searchable(),
                    MemberTableColumns::relationNumberFor(
                        memberNumberColumn: 'fromMember.member_number',
                        memberIdColumn: 'member_cash_transfer_requests.from_member_id',
                        label: __('From #'),
                    )->url(fn (MemberCashTransferRequest $record): ?string => $record->fromMember
                            ? MemberTableColumns::memberRecordUrl($record->fromMember)
                            : null),
                    TextColumn::make('fromMember.name')
                        ->label(__('From'))
                        ->searchable()
                        ->sortable()
                        ->wrap(),
                    TextColumn::make('recipient_name')
                        ->label(__('To (requested)'))
                        ->searchable()
                        ->wrap(),
                    TextColumn::make('toMember.name')
                        ->label(__('To (resolved)'))
                        ->placeholder(__('—'))
                        ->wrap(),
                    TextColumn::make('amount')
                        ->money(fn (): string => Setting::get('general', 'currency', 'USD'))
                        ->sortable(),
                    TextColumn::make('status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'pending' => 'warning',
                            'accepted' => 'success',
                            'rejected' => 'danger',
                            default => 'gray',
                        }),
                    TextColumn::make('created_at')
                        ->label(__('Submitted'))
                        ->dateTime()
                        ->sortable(),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options([
                            'pending' => __('Pending'),
                            'accepted' => __('Accepted'),
                            'rejected' => __('Rejected'),
                        ]),
                    SelectFilter::make('from_member_id')
                        ->label(__('From member'))
                        ->relationship('fromMember', 'name')
                        ->searchable()
                        ->preload(),
                    DateColumnRangeFilter::make('created_at', __('Submitted')),
                ])
                ->recordActions(TableRecordActionGroups::wrap([
                    Action::make('accept')
                        ->label(__('Accept'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading(__('Accept cash transfer'))
                        ->modalDescription(__('Moves cash from the sender to the recipient with master cash mirrors. No bank statement line is created.'))
                        ->visible(fn (MemberCashTransferRequest $record): bool => $record->status === 'pending')
                        ->fillForm(fn (MemberCashTransferRequest $record): array => [
                            'to_member_id' => $record->to_member_id,
                        ])
                        ->schema([
                            MemberSelect::make('to_member_id')
                                ->label(__('Recipient member'))
                                ->required()
                                ->helperText(__('Confirm or choose the member matching the requested name.')),
                            Textarea::make('admin_remarks')
                                ->label(__('Remarks (optional)'))
                                ->rows(2),
                        ])
                        ->action(function (MemberCashTransferRequest $record, array $data, Action $action, MemberCashTransferService $service): void {
                            if (
                                ! ActionModalFailure::attemptThrowable(
                                    $action,
                                    fn () => $service->accept(
                                        $record,
                                        auth()->id(),
                                        $data['admin_remarks'] ?? null,
                                        isset($data['to_member_id']) ? (int) $data['to_member_id'] : null,
                                    ),
                                    __('Could not accept transfer'),
                                )
                            ) {
                                return;
                            }

                            Notification::make()->title(__('Cash transfer accepted'))->success()->send();
                        }),
                    Action::make('reject')
                        ->label(__('Reject'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(__('Reject cash transfer'))
                        ->visible(fn (MemberCashTransferRequest $record): bool => $record->status === 'pending')
                        ->schema([
                            Textarea::make('admin_remarks')
                                ->label(__('Reason for rejection'))
                                ->required()
                                ->rows(2),
                        ])
                        ->action(function (MemberCashTransferRequest $record, array $data, Action $action, MemberCashTransferService $service): void {
                            if (
                                ! ActionModalFailure::attemptThrowable(
                                    $action,
                                    fn () => $service->reject($record, auth()->id(), $data['admin_remarks']),
                                    __('Could not reject transfer'),
                                )
                            ) {
                                return;
                            }

                            Notification::make()->title(__('Cash transfer rejected'))->send();
                        }),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ])
                ->defaultSort('created_at', 'desc'),
            TableGrouping::fundPostings(includeMember: false),
        );
    }
}
