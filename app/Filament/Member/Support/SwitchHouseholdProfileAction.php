<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\Tenant\HouseholdProfileVerificationService;
use App\Services\Tenant\ImpersonationService;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

final class SwitchHouseholdProfileAction
{
    public static function make(): Action
    {
        return Action::make('switchHouseholdProfile')
            ->label(__('Switch'))
            ->modalHeading(__('Verify profile access'))
            ->modalDescription(__('Enter this member\'s portal password to continue.'))
            ->modalSubmitActionLabel(__('Continue'))
            ->schema([
                TextInput::make('verification_secret')
                    ->label(__('Verification code/password'))
                    ->password()
                    ->revealable()
                    ->required()
                    ->autocomplete('off')
                    ->placeholder(__('Enter dependent password')),
            ])
            ->action(function (array $data, array $arguments, Action $action): void {
                $member = Member::query()->find((int) ($arguments['memberId'] ?? 0));

                if (! $member instanceof Member) {
                    Notification::make()
                        ->title(__('Invalid profile selected.'))
                        ->danger()
                        ->send();

                    return;
                }

                self::switchAfterVerification($member, (string) ($data['verification_secret'] ?? ''), $action);
            });
    }

    public static function switchAfterVerification(Member $member, string $verificationSecret, Action $action): void
    {
        $actor = auth('tenant')->user();

        if (! $actor instanceof User) {
            return;
        }

        $parentMember = Member::query()
            ->where('user_id', $actor->id)
            ->whereNull('parent_member_id')
            ->first();

        if (
            $parentMember === null
            || (int) $member->parent_member_id !== (int) $parentMember->id
            || $member->isParent()
        ) {
            Notification::make()
                ->title(__('Invalid profile selected.'))
                ->danger()
                ->send();

            return;
        }

        $throttleKey = 'household_profile_switch|'.Str::transliterate(Str::lower((string) $actor->email).'|'.request()->ip());

        if (RateLimiter::tooManyAttempts($throttleKey, 5)) {
            Notification::make()
                ->title(__('Too many attempts. Please try again later.'))
                ->danger()
                ->send();

            return;
        }

        $verifier = app(HouseholdProfileVerificationService::class);

        if (! $verifier->memberCanUsePortal($member)) {
            Notification::make()
                ->title(__('This profile is not available for portal access.'))
                ->danger()
                ->send();

            return;
        }

        $dependentUser = $verifier->resolveLoginUser($member);

        if ($dependentUser === null) {
            Notification::make()
                ->title(__('This profile does not have a login account yet.'))
                ->danger()
                ->send();

            return;
        }

        if (! $verifier->verifyMemberSecret($member, $verificationSecret)) {
            RateLimiter::hit($throttleKey, 300);

            Notification::make()
                ->title(__('The dependent password is incorrect.'))
                ->danger()
                ->send();

            return;
        }

        RateLimiter::clear($throttleKey);

        app(ImpersonationService::class)->start($actor, $dependentUser, $member);

        $action->redirect(filament()->getPanel('member')?->getUrl() ?? '/member');
    }
}
