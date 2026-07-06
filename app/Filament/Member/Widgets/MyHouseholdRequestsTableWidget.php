<?php

declare(strict_types=1);

namespace App\Filament\Member\Widgets;

use App\Filament\Member\Support\ViewMemberRequestAction;
use App\Filament\Support\TableRecordActionGroups;
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
use Filament\Tables\Table;
use Filament\Widgets\TableWidget;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Validation\ValidationException;

class MyHouseholdRequestsTableWidget extends TableWidget
{
    protected static bool $isDiscovered = false;

    protected int|string|array $columnSpan = 'full';

    public function getHeading(): ?string
    {
        return __('Household requests');
    }

    protected function getTableQuery(): Builder|Relation|null
    {
        $member = CurrentMember::get();

        if ($member === null) {
            return MemberRequest::query()->whereRaw('1 = 0');
        }

        return MemberRequest::query()
            ->where('requester_member_id', $member->id)
            ->whereIn('type', [
                MemberRequest::TYPE_ADD_DEPENDENT,
                MemberRequest::TYPE_REMOVE_DEPENDENT,
            ]);
    }

    public function table(Table $table): Table
    {
        $service = app(MemberRequestService::class);

        return TableRecordActionGroups::apply(
            $table
                ->heading(__('Household requests'))
                ->description(__('Ask administration to link a new dependent or remove someone from your household.'))
                ->headerActions([
                    Action::make('requestAddDependent')
                        ->label(__('Add a dependent'))
                        ->icon('heroicon-o-user-plus')
                        ->color('success')
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
                    Action::make('applyForDependent')
                        ->label(__('Apply for a dependent'))
                        ->icon('heroicon-o-document-plus')
                        ->color('primary')
                        ->url(fn (): string => route('tenant.membership', ['on_behalf' => 1]))
                        ->openUrlInNewTab(),
                    Action::make('requestRemoveDependent')
                        ->label(__('Remove a dependent'))
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
                    TextColumn::make('created_at')
                        ->label(__('Submitted'))
                        ->dateTime()
                        ->sortable(),
                ])
                ->defaultSort('created_at', 'desc')
                ->emptyStateHeading(__('No household requests'))
                ->emptyStateDescription(__('Use the actions above to ask for a new dependent or to remove someone from your household.'))
                ->toolbarActions([
                    BulkActionGroup::make([
                        TableToolbar::refreshBulkAction(),
                    ]),
                ]),
            [ViewMemberRequestAction::make()],
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
