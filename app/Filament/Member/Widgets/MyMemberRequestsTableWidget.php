<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Filament\Support\TableGrouping;
use App\Filament\Support\TableToolbar;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Services\Tenant\MemberRequestService;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Actions\BulkActionGroup;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Grouping\Group;
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;

class MyMemberRequestsTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return __('Your requests');
    }

    protected function getTableQuery(): Builder|Relation|null
    {
        $member = CurrentMember::get();

        if ($member === null) {
            return MemberRequest::query()->whereRaw('1 = 0');
        }

        return MemberRequest::query()
            ->where('requester_member_id', $member->id);
    }

    public function table(Table $table): Table
    {
        $service = app(MemberRequestService::class);

        return TableGrouping::apply(
            $table
                ->heading(__('Your requests'))
                ->description(__('Track requests you have submitted. Use the actions above to ask for independence or to add or remove a dependent. Pending items are reviewed by administration.'))
                ->headerActions([
                    Action::make('requestIndependence')
                        ->label(__('Become independent'))
                        ->icon('heroicon-o-arrow-right-start-on-rectangle')
                        ->color('warning')
                        ->visible(fn (): bool => CurrentMember::get()?->parent_member_id !== null)
                        ->requiresConfirmation()
                        ->modalHeading(__('Request independence'))
                        ->modalDescription(__('You will no longer be sponsored under a household parent. Allocation updates are already self-service, while dependent-link changes continue through requests.'))
                        ->action(function () use ($service): void {
                            $member = CurrentMember::get();

                            if ($member === null) {
                                return;
                            }

                            try {
                                $service->submit($member, MemberRequest::TYPE_REQUEST_INDEPENDENCE, []);
                                Notification::make()->title(__('Request submitted'))->success()->send();
                            } catch (ValidationException $exception) {
                                $this->validationToNotification($exception);
                            }
                        }),
                    Action::make('requestAddDependent')
                        ->label(__('Request to add a dependent'))
                        ->icon('heroicon-o-user-plus')
                        ->visible(fn (): bool => CurrentMember::get() !== null)
                        ->schema([
                            Textarea::make('details')
                                ->label(__('Who should be added?'))
                                ->required()
                                ->rows(4)
                                ->helperText(__('Include name and any details the office needs to link a new or existing member.')),
                        ])
                        ->action(function (array $data) use ($service): void {
                            $member = CurrentMember::get();

                            if ($member === null) {
                                return;
                            }

                            try {
                                $service->submit($member, MemberRequest::TYPE_ADD_DEPENDENT, [
                                    'details' => $data['details'],
                                ]);
                                Notification::make()->title(__('Request submitted'))->success()->send();
                            } catch (ValidationException $exception) {
                                $this->validationToNotification($exception);
                            }
                        }),
                    Action::make('requestRemoveDependent')
                        ->label(__('Request to remove a dependent'))
                        ->icon('heroicon-o-user-minus')
                        ->color('danger')
                        ->visible(fn (): bool => CurrentMember::get()?->dependents()->exists() ?? false)
                        ->schema([
                            Select::make('dependent_member_id')
                                ->label(__('Dependent'))
                                ->options(function (): array {
                                    $member = CurrentMember::get();

                                    if ($member === null) {
                                        return [];
                                    }

                                    return $member->dependents()
                                        ->orderBy('member_number')
                                        ->get()
                                        ->mapWithKeys(fn (Member $dependent): array => [
                                            $dependent->id => $dependent->member_number.' — '.$dependent->name,
                                        ])
                                        ->all();
                                })
                                ->required()
                                ->searchable(),
                        ])
                        ->action(function (array $data) use ($service): void {
                            $member = CurrentMember::get();

                            if ($member === null) {
                                return;
                            }

                            try {
                                $service->submit($member, MemberRequest::TYPE_REMOVE_DEPENDENT, [
                                    'dependent_member_id' => (int) $data['dependent_member_id'],
                                ]);
                                Notification::make()->title(__('Request submitted'))->success()->send();
                            } catch (ValidationException $exception) {
                                $this->validationToNotification($exception);
                            }
                        }),
                ])
                ->filters([
                    SelectFilter::make('status')
                        ->options(MemberRequest::statusOptions()),
                ])
                ->columns([
                    TextColumn::make('type')
                        ->label(__('Request'))
                        ->formatStateUsing(fn (string $state): string => MemberRequest::typeLabel($state)),
                    TextColumn::make('details_display')
                        ->label(__('Details'))
                        ->visibleFrom('md')
                        ->getStateUsing(fn (MemberRequest $record): string => $record->describePayload())
                        ->wrap(),
                    TextColumn::make('status')
                        ->label(__('Status'))
                        ->badge()
                        ->formatStateUsing(fn (string $state): string => MemberRequest::statusOptions()[$state] ?? $state)
                        ->color(fn (string $state): string => match ($state) {
                            MemberRequest::STATUS_PENDING => 'warning',
                            MemberRequest::STATUS_APPROVED => 'success',
                            MemberRequest::STATUS_REJECTED => 'danger',
                            MemberRequest::STATUS_CANCELLED => 'gray',
                            default => 'gray',
                        }),
                    TextColumn::make('admin_note')
                        ->label(__('Admin note'))
                        ->visibleFrom('lg')
                        ->placeholder(__('—'))
                        ->toggleable(isToggledHiddenByDefault: true),
                    TextColumn::make('created_at')
                        ->label(__('Submitted'))
                        ->dateTime()
                        ->sortable(),
                ])
                ->defaultSort('created_at', 'desc')
                ->emptyStateHeading(__('No member requests'))
                ->emptyStateDescription(__('You have not submitted any member requests yet.'))
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ]),
            [
                Group::make('status')
                    ->label(__('Status'))
                    ->titlePrefixedWithLabel(false)
                    ->getTitleFromRecordUsing(fn (MemberRequest $record): string => MemberRequest::statusOptions()[$record->status] ?? $record->status),
            ],
        );
    }

    protected function validationToNotification(ValidationException $exception): void
    {
        $message = collect($exception->errors())->flatten()->first() ?? $exception->getMessage();

        Notification::make()
            ->title(__('Could not submit'))
            ->body($message)
            ->danger()
            ->send();
    }
}
