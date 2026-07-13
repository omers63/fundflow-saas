<?php

declare(strict_types=1);

namespace App\Filament\Member\Resources\MyDependents\Support;

use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Models\Tenant\User;
use App\Rules\NewMemberLoginEmail;
use App\Services\Tenant\MemberRequestService;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

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
            ->modalHeading(__('Add a dependent'))
            ->modalDescription(fn (): string => CurrentMember::get()?->isSponsoredDependent()
                ? __('You will leave your current household and become a parent. Enter a unique email for your login, then describe who should be added.')
                : __('Describe who administration should link as a new dependent under your household.'))
            ->modalSubmitActionLabel(__('Submit request'))
            ->schema(self::requestAddDependentSchema())
            ->action(function (array $data): void {
                $member = CurrentMember::get();

                if ($member === null) {
                    return;
                }

                try {
                    app(MemberRequestService::class)->submit($member, MemberRequest::TYPE_ADD_DEPENDENT, [
                        'details' => $data['details'],
                        'new_email' => filled($data['new_email'] ?? null) ? (string) $data['new_email'] : null,
                    ]);

                    Notification::make()->title(__('Request submitted'))->success()->send();
                } catch (ValidationException $exception) {
                    self::validationToNotification($exception);
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title(__('Could not submit'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
                }
            });
    }

    /**
     * @return list<TextInput|Textarea>
     */
    private static function requestAddDependentSchema(): array
    {
        return [
            TextInput::make('new_email')
                ->label(__('Your new email'))
                ->email()
                ->maxLength(255)
                ->visible(fn (): bool => CurrentMember::get()?->isSponsoredDependent() ?? false)
                ->required(fn (): bool => CurrentMember::get()?->isSponsoredDependent() ?? false)
                ->dehydrated(fn (): bool => CurrentMember::get()?->isSponsoredDependent() ?? false)
                ->rules(fn (): array => [
                    'required',
                    'email',
                    'max:255',
                    Rule::unique(User::class, 'email')->ignore(CurrentMember::get()?->user_id),
                    new NewMemberLoginEmail(CurrentMember::get()?->user_id),
                ])
                ->validationMessages([
                    'required' => __('Enter a unique email for your login before requesting a dependent.'),
                    'email' => __('Enter a valid email address.'),
                    'unique' => __('This email is already in use. Choose another.'),
                ])
                ->helperText(__('Choose a unique email for your login before we send this request.')),
            Textarea::make('details')
                ->label(__('Who should be added?'))
                ->required()
                ->rows(4)
                ->helperText(__('Include name and any details the office needs to link a new or existing member.')),
        ];
    }

    private static function applyForDependentAction(): Action
    {
        return Action::make('applyForDependent')
            ->icon('heroicon-o-document-plus')
            ->color('primary')
            ->iconButton()
            ->tooltip(__('Apply for a dependent'))
            ->visible(fn (): bool => CurrentMember::get()?->isParent() ?? false)
            ->url(fn (): string => route('tenant.membership', ['on_behalf' => 1]))
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
            ->modalHeading(__('Remove a dependent'))
            ->modalDescription(__('The dependent will leave your household and need their own unique login email.'))
            ->modalSubmitActionLabel(__('Submit request'))
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
                    ->searchable()
                    ->live(),
                TextInput::make('separated_email')
                    ->label(__('Dependent\'s new email'))
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->rules(fn (Get $get): array => [
                        'required',
                        'email',
                        'max:255',
                        Rule::unique(User::class, 'email')->ignore(
                            Member::query()->find((int) $get('dependent_member_id'))?->user_id,
                        ),
                        new NewMemberLoginEmail(
                            Member::query()->find((int) $get('dependent_member_id'))?->user_id,
                        ),
                    ])
                    ->validationMessages([
                        'required' => __('Enter a unique email for the dependent\'s login.'),
                        'email' => __('Enter a valid email address.'),
                        'unique' => __('This email is already in use. Choose another.'),
                    ])
                    ->helperText(__('Required. The dependent will use this email after they are separated from your household.')),
            ])
            ->action(function (array $data) use ($service): void {
                $member = CurrentMember::get();

                if ($member === null) {
                    return;
                }

                try {
                    $service->submit($member, MemberRequest::TYPE_REMOVE_DEPENDENT, [
                        'dependent_member_id' => (int) $data['dependent_member_id'],
                        'separated_email' => (string) $data['separated_email'],
                    ]);
                    Notification::make()->title(__('Request submitted'))->success()->send();
                } catch (ValidationException $exception) {
                    self::validationToNotification($exception);
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title(__('Could not submit'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();
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
