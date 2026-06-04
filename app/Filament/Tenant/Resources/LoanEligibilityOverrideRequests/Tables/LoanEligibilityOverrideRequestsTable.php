<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\LoanEligibilityOverrideRequests\Tables;

use App\Filament\Support\ActionModalFailure;
use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Resources\LoanEligibilityOverrideRequests\LoanEligibilityOverrideRequestResource;
use App\Models\Tenant\LoanEligibilityOverrideRequest;
use App\Services\Loans\LoanEligibilityOverrideRequestService;
use App\Support\LoanEligibilityGate;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Infolists\Components\TextEntry;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Livewire\Component;

class LoanEligibilityOverrideRequestsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            $table
                ->columns([
                    MemberTableColumns::relationName(),
                    TextColumn::make('failed_gates')
                        ->label(__('Blocked rules'))
                        ->formatStateUsing(function (mixed $state): string {
                            if (is_string($state)) {
                                $state = json_decode($state, true);
                            }

                            if (! is_array($state) || $state === []) {
                                return __('—');
                            }

                            $labels = LoanEligibilityGate::labels();

                            return collect(array_keys($state))
                                ->map(fn (string $gate): string => $labels[$gate] ?? $gate)
                                ->implode(', ');
                        })
                        ->wrap(),
                    TextColumn::make('member_message')
                        ->label(__('Member message'))
                        ->limit(40)
                        ->wrap()
                        ->placeholder(__('—')),
                    TextColumn::make('status')
                        ->badge()
                        ->color(fn (string $state): string => match ($state) {
                            'pending' => 'warning',
                            'approved' => 'success',
                            'rejected' => 'danger',
                            default => 'gray',
                        }),
                    TextColumn::make('created_at')
                        ->label(__('Submitted'))
                        ->dateTime()
                        ->sortable(),
                    TextColumn::make('reviewed_at')
                        ->label(__('Reviewed'))
                        ->dateTime()
                        ->placeholder(__('—'))
                        ->sortable(),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options([
                            'pending' => 'Pending',
                            'approved' => 'Approved',
                            'rejected' => 'Rejected',
                        ])
                        ->default('pending'),
                    SelectFilter::make('member_id')
                        ->label(__('Member'))
                        ->relationship('member', 'name')
                        ->searchable()
                        ->preload(),
                    DateColumnRangeFilter::make('created_at', __('Submitted')),
                ])
                ->recordActions(TableRecordActionGroups::wrap([
                    Action::make('view')
                        ->label(__('View'))
                        ->icon('heroicon-o-eye')
                        ->modalHeading(__('Eligibility review request'))
                        ->modalWidth('lg')
                        ->modalSubmitAction(false)
                        ->modalCancelActionLabel(__('Close'))
                        ->schema(fn (LoanEligibilityOverrideRequest $record): array => [
                            Section::make(__('Request'))
                                ->schema([
                                    TextEntry::make('member.name')->label(__('Member')),
                                    TextEntry::make('status')->label(__('Status')),
                                    TextEntry::make('created_at')->dateTime()->label(__('Submitted')),
                                    TextEntry::make('member_message')
                                        ->label(__('Member message'))
                                        ->columnSpanFull(),
                                    TextEntry::make('blocked_rules')
                                        ->label(__('Blocked rules'))
                                        ->state(fn (): string => implode("\n", app(LoanEligibilityOverrideRequestService::class)->summarizeFailedGates($record)))
                                        ->columnSpanFull(),
                                    TextEntry::make('admin_remarks')
                                        ->label(__('Admin remarks'))
                                        ->placeholder(__('—'))
                                        ->visible(fn (): bool => filled($record->admin_remarks))
                                        ->columnSpanFull(),
                                ])
                                ->columns(2),
                        ]),
                    Action::make('approve')
                        ->label(__('Approve'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->requiresConfirmation()
                        ->modalHeading(__('Approve eligibility review'))
                        ->modalDescription(__('Creates standing overrides for each blocked rule on this request.'))
                        ->modalWidth('md')
                        ->hidden(fn (LoanEligibilityOverrideRequest $record): bool => $record->status !== 'pending')
                        ->schema([
                            Textarea::make('admin_remarks')
                                ->label(__('Remarks (optional)'))
                                ->rows(2),
                        ])
                        ->action(function (LoanEligibilityOverrideRequest $record, array $data, Action $action, LoanEligibilityOverrideRequestService $service, Component $livewire): void {
                            if (
                                ! ActionModalFailure::attemptThrowable(
                                    $action,
                                    fn () => $service->approve($record, auth()->id(), $data['admin_remarks'] ?? null),
                                    __('Could not approve eligibility review'),
                                )
                            ) {
                                return;
                            }

                            Notification::make()
                                ->title(__('Eligibility review approved'))
                                ->success()
                                ->send();

                            LoanEligibilityOverrideRequestResource::dispatchNotificationsRefresh($livewire);
                        }),
                    Action::make('reject')
                        ->label(__('Reject'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->requiresConfirmation()
                        ->modalHeading(__('Reject eligibility review'))
                        ->modalWidth('md')
                        ->hidden(fn (LoanEligibilityOverrideRequest $record): bool => $record->status !== 'pending')
                        ->schema([
                            Textarea::make('admin_remarks')
                                ->label(__('Reason for rejection'))
                                ->required()
                                ->rows(2),
                        ])
                        ->action(function (LoanEligibilityOverrideRequest $record, array $data, Action $action, LoanEligibilityOverrideRequestService $service, Component $livewire): void {
                            if (
                                ! ActionModalFailure::attemptThrowable(
                                    $action,
                                    fn () => $service->reject($record, auth()->id(), $data['admin_remarks']),
                                    __('Could not reject eligibility review'),
                                )
                            ) {
                                return;
                            }

                            Notification::make()
                                ->title(__('Eligibility review rejected'))
                                ->send();

                            LoanEligibilityOverrideRequestResource::dispatchNotificationsRefresh($livewire);
                        }),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ])
                ->defaultSort('created_at', 'desc'),
            TableGrouping::loanEligibilityOverrideRequests(),
        );
    }
}
