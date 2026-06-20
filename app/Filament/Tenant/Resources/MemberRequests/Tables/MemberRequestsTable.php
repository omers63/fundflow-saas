<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Resources\MemberRequests\Tables;

use App\Filament\Support\DateColumnRangeFilter;
use App\Filament\Support\MemberTableColumns;
use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableRecordActionGroups;
use App\Filament\Support\TableToolbar;
use App\Filament\Tenant\Support\ViewMemberRequestAction;
use App\Models\Tenant\MemberRequest;
use App\Services\Tenant\MemberRequestService;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

final class MemberRequestsTable
{
    public static function configure(Table $table): Table
    {
        return TableGrouping::apply(
            $table
                ->modifyQueryUsing(
                    fn (Builder $query): Builder => $query->with([
                        'requester',
                        'reviewedBy',
                    ])
                )
                ->columns([
                    TextColumn::make('requester.member_number')
                        ->label(__('Member #'))
                        ->url(fn (MemberRequest $record): ?string => $record->requester
                            ? MemberTableColumns::memberRecordEditUrl($record->requester)
                            : null)
                        ->sortable(),
                    TextColumn::make('requester.name')
                        ->label(__('Member'))
                        ->searchable()
                        ->sortable(),
                    TextColumn::make('type')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => MemberRequest::typeLabel($state)),
                    TextColumn::make('details_display')
                        ->label(__('Details'))
                        ->getStateUsing(fn (MemberRequest $record): string => $record->describePayload())
                        ->wrap()
                        ->limit(80),
                    TextColumn::make('status')
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => MemberRequest::statusOptions()[$state] ?? $state)
                        ->color(fn (string $state): string => match ($state) {
                            MemberRequest::STATUS_PENDING => 'warning',
                            MemberRequest::STATUS_APPROVED => 'success',
                            MemberRequest::STATUS_REJECTED => 'danger',
                            MemberRequest::STATUS_CANCELLED => 'gray',
                            default => 'gray',
                        }),
                    TextColumn::make('created_at')
                        ->label(__('Submitted'))
                        ->dateTime()
                        ->sortable(),
                    TextColumn::make('reviewedBy.name')
                        ->label(__('Reviewed by'))
                        ->placeholder(__('—'))
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('reviewed_at')
                        ->dateTime()
                        ->placeholder(__('—'))
                        ->toggleable(isToggledHiddenByDefault: true),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options(MemberRequest::statusOptions()),
                    SelectFilter::make('type')
                        ->options(MemberRequest::typeOptions()),
                    DateColumnRangeFilter::make('created_at', 'Submitted'),
                ])
                ->defaultSort('created_at', 'desc')
                ->recordActions(TableRecordActionGroups::wrap([
                    ViewMemberRequestAction::make(),
                    Action::make('approve')
                        ->label(__('Approve'))
                        ->icon('heroicon-o-check-circle')
                        ->color('success')
                        ->visible(fn (MemberRequest $record): bool => $record->isPending())
                        ->requiresConfirmation()
                        ->modalHeading(__('Approve this request?'))
                        ->modalDescription(__('The change will be applied immediately for supported request types.'))
                        ->action(function (MemberRequest $record): void {
                            try {
                                app(MemberRequestService::class)->approve(
                                    $record,
                                    auth('tenant')->user(),
                                );
                                Notification::make()->title(__('Request approved'))->success()->send();
                            } catch (ValidationException $exception) {
                                Notification::make()
                                    ->title(__('Cannot approve'))
                                    ->body(collect($exception->errors())->flatten()->first() ?? $exception->getMessage())
                                    ->danger()
                                    ->send();
                            }
                        }),
                    Action::make('reject')
                        ->label(__('Reject'))
                        ->icon('heroicon-o-x-circle')
                        ->color('danger')
                        ->visible(fn (MemberRequest $record): bool => $record->isPending())
                        ->schema([
                            Textarea::make('admin_note')
                                ->label(__('Note to member (optional)'))
                                ->rows(3)
                                ->maxLength(2000),
                        ])
                        ->action(function (MemberRequest $record, array $data): void {
                            app(MemberRequestService::class)->reject(
                                $record,
                                auth('tenant')->user(),
                                $data['admin_note'] ?? null,
                            );
                            Notification::make()->title(__('Request rejected'))->success()->send();
                        }),
                    DeleteAction::make(),
                ]))
                ->toolbarActions([
                    BulkActionGroup::make([
                        BulkAction::make('approveSelected')
                            ->label(__('Approve selected'))
                            ->icon('heroicon-o-check-circle')
                            ->color('success')
                            ->requiresConfirmation()
                            ->modalHeading(__('Approve selected requests'))
                            ->modalDescription(__('Only rows that are still pending are processed; each is approved like the row action. Other rows are skipped.'))
                            ->action(function (Collection $records): void {
                                $service = app(MemberRequestService::class);
                                $admin = auth('tenant')->user();
                                $pending = $records->filter(fn (MemberRequest $record): bool => $record->isPending())->values();
                                $skipped = $records->count() - $pending->count();
                                $approved = 0;
                                $failed = 0;

                                foreach ($pending as $record) {
                                    try {
                                        $service->approve($record, $admin);
                                        $approved++;
                                    } catch (ValidationException) {
                                        $failed++;
                                    } catch (\Throwable $exception) {
                                        $failed++;
                                        report($exception);
                                    }
                                }

                                Notification::make()
                                    ->title(__('Bulk approve finished'))
                                    ->body(__('Approved: :approved. Failed: :failed. Skipped (not pending): :skipped.', [
                                        'approved' => $approved,
                                        'failed' => $failed,
                                        'skipped' => $skipped,
                                    ]))
                                    ->color($failed > 0 ? 'warning' : 'success')
                                    ->send();
                            })
                            ->deselectRecordsAfterCompletion(),
                        BulkAction::make('rejectSelected')
                            ->label(__('Reject selected'))
                            ->icon('heroicon-o-x-circle')
                            ->color('danger')
                            ->schema([
                                Textarea::make('admin_note')
                                    ->label(__('Note to members (optional)'))
                                    ->rows(3)
                                    ->maxLength(2000),
                            ])
                            ->modalHeading(__('Reject selected requests'))
                            ->modalDescription(__('The note below is stored on each selected row that is still pending. Other rows are skipped.'))
                            ->action(function (Collection $records, array $data): void {
                                $service = app(MemberRequestService::class);
                                $admin = auth('tenant')->user();
                                $note = $data['admin_note'] ?? null;
                                $pending = $records->filter(fn (MemberRequest $record): bool => $record->isPending())->values();
                                $skipped = $records->count() - $pending->count();
                                $rejected = 0;
                                $failed = 0;

                                foreach ($pending as $record) {
                                    try {
                                        $service->reject($record, $admin, $note);
                                        $rejected++;
                                    } catch (ValidationException) {
                                        $failed++;
                                    } catch (\Throwable $exception) {
                                        $failed++;
                                        report($exception);
                                    }
                                }

                                Notification::make()
                                    ->title(__('Bulk reject finished'))
                                    ->body(__('Rejected: :rejected. Failed: :failed. Skipped (not pending): :skipped.', [
                                        'rejected' => $rejected,
                                        'failed' => $failed,
                                        'skipped' => $skipped,
                                    ]))
                                    ->color($failed > 0 ? 'danger' : 'warning')
                                    ->send();
                            })
                            ->deselectRecordsAfterCompletion(),
                        DeleteBulkAction::make(),
                        TableToolbar::refreshBulkAction(),
                    ]),
                ]),
            [
                Group::make('status')
                    ->label(__('Status'))
                    ->titlePrefixedWithLabel(false)
                    ->getTitleFromRecordUsing(fn (MemberRequest $record): string => MemberRequest::statusOptions()[$record->status] ?? $record->status),
                Group::make('type')
                    ->label(__('Type'))
                    ->titlePrefixedWithLabel(false)
                    ->getTitleFromRecordUsing(fn (MemberRequest $record): string => MemberRequest::typeLabel($record->type)),
            ],
        );
    }
}
