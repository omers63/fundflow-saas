<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyDependents\Support;

use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Services\Tenant\MemberRequestService;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

final class DependentsTableHeaderActions
{
    /**
     * @return list<Action>
     */
    public static function actions(): array
    {
        return [
            self::requestAddDependentAction(),
            self::applyForDependentAction(),
            self::requestRemoveDependentAction(),
        ];
    }

    private static function requestAddDependentAction(): Action
    {
        return Action::make('requestAddDependent')
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->iconButton()
            ->tooltip(__('Add a dependent'))
            ->schema(function (): array {
                $member = CurrentMember::get();

                $fields = [
                    Textarea::make('details')
                        ->label(__('Who should be added?'))
                        ->required()
                        ->rows(4)
                        ->helperText(__('Include name and any details the office needs to link a new or existing member.')),
                ];

                if ($member?->isSponsoredDependent() ?? false) {
                    array_unshift($fields, TextInput::make('new_email')
                        ->label(__('Your new email'))
                        ->email()
                        ->required()
                        ->helperText(__('You will leave your current household and become a parent. Choose a unique email for your login before we send this request.')));
                }

                return $fields;
            })
            ->action(function (array $data): void {
                $member = CurrentMember::get();

                if ($member === null) {
                    return;
                }

                try {
                    app(MemberRequestService::class)->submit($member, MemberRequest::TYPE_ADD_DEPENDENT, [
                        'details' => $data['details'],
                        'new_email' => $data['new_email'] ?? null,
                    ]);
                    Notification::make()->title(__('Request submitted'))->success()->send();
                } catch (ValidationException $exception) {
                    self::validationToNotification($exception);
                }
            });
    }

    private static function applyForDependentAction(): Action
    {
        return Action::make('applyForDependent')
            ->icon('heroicon-o-document-plus')
            ->color('primary')
            ->iconButton()
            ->tooltip(__('Apply for a dependent'))
            ->visible(fn(): bool => CurrentMember::get()?->isParent() ?? false)
            ->url(fn(): string => route('tenant.membership', ['on_behalf' => 1]))
            ->openUrlInNewTab();
    }

    private static function requestRemoveDependentAction(): Action
    {
        $service = app(MemberRequestService::class);

        return Action::make('requestRemoveDependent')
            ->icon('heroicon-o-user-minus')
            ->color('danger')
            ->iconButton()
            ->tooltip(__('Remove a dependent'))
            ->visible(fn(): bool => CurrentMember::get()?->dependents()->exists() ?? false)
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
                            ->mapWithKeys(fn(Member $dependent): array => [
                                $dependent->id => $dependent->member_number . ' — ' . $dependent->name,
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
                    self::validationToNotification($exception);
                }
            });
    }

    protected static function validationToNotification(ValidationException $exception): void
    {
        $message = collect($exception->errors())->flatten()->first() ?? $exception->getMessage();

        Notification::make()
            ->title(__('Could not submit'))
            ->body($message)
            ->danger()
            ->send();
    }
}
