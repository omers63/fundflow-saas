<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Services\Tenant\MemberRequestService;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;

final class MemberRequestFilamentActions
{
    /**
     * @return list<Action>
     */
    public static function membershipHeaderActions(): array
    {
        $service = app(MemberRequestService::class);

        return [
            ActionGroup::make([
                self::freezeMembershipAction($service),
                self::unfreezeMembershipAction($service),
                self::withdrawMembershipAction($service),
                self::independenceAction($service),
            ])
                ->label(__('New request'))
                ->icon('heroicon-o-plus')
                ->color('primary')
                ->button(),
        ];
    }

    private static function freezeMembershipAction(MemberRequestService $service): Action
    {
        return Action::make('requestFreezeMembership')
            ->label(__('Freeze membership'))
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->visible(fn (): bool => CurrentMember::get()?->status === 'active')
            ->schema([
                Textarea::make('reason')
                    ->label(__('Reason (optional)'))
                    ->rows(3)
                    ->maxLength(500),
            ])
            ->requiresConfirmation()
            ->modalHeading(__('Request membership freeze'))
            ->modalDescription(__('Pauses your portal access and contribution cycles until administration approves and later unfreezes your account.'))
            ->action(fn (array $data) => self::submit($service, MemberRequest::TYPE_FREEZE_MEMBERSHIP, [
                'reason' => $data['reason'] ?? '',
            ]));
    }

    private static function unfreezeMembershipAction(MemberRequestService $service): Action
    {
        return Action::make('requestUnfreezeMembership')
            ->label(__('Unfreeze membership'))
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->visible(fn (): bool => CurrentMember::get()?->status === 'inactive')
            ->schema([
                Textarea::make('reason')
                    ->label(__('Reason (optional)'))
                    ->rows(3)
                    ->maxLength(500),
            ])
            ->requiresConfirmation()
            ->action(fn (array $data) => self::submit($service, MemberRequest::TYPE_UNFREEZE_MEMBERSHIP, [
                'reason' => $data['reason'] ?? '',
            ]));
    }

    private static function withdrawMembershipAction(MemberRequestService $service): Action
    {
        return Action::make('requestWithdrawMembership')
            ->label(__('Withdraw from fund'))
            ->icon('heroicon-o-arrow-right-on-rectangle')
            ->color('danger')
            ->visible(fn (): bool => in_array(CurrentMember::get()?->status, ['active', 'inactive'], true))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Reason (optional)'))
                    ->rows(3)
                    ->maxLength(500),
            ])
            ->requiresConfirmation()
            ->modalHeading(__('Request withdrawal'))
            ->modalDescription(__('Voluntary exit from the fund. Administration will review before your membership is closed.'))
            ->action(fn (array $data) => self::submit($service, MemberRequest::TYPE_WITHDRAW_MEMBERSHIP, [
                'reason' => $data['reason'] ?? '',
            ]));
    }

    private static function independenceAction(MemberRequestService $service): Action
    {
        return Action::make('requestIndependence')
            ->label(__('Become independent'))
            ->icon('heroicon-o-arrow-right-start-on-rectangle')
            ->color('warning')
            ->visible(fn (): bool => CurrentMember::get()?->parent_member_id !== null)
            ->requiresConfirmation()
            ->modalHeading(__('Request independence'))
            ->modalDescription(__('Leave your household parent’s sponsorship. Dependent add/remove requests are managed on the My dependents page.'))
            ->action(fn () => self::submit($service, MemberRequest::TYPE_REQUEST_INDEPENDENCE, []));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private static function submit(MemberRequestService $service, string $type, array $payload): void
    {
        $member = CurrentMember::get();

        if (! $member instanceof Member) {
            return;
        }

        try {
            $service->submit($member, $type, $payload);
            Notification::make()->title(__('Request submitted'))->success()->send();
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first() ?? $exception->getMessage();

            Notification::make()
                ->title(__('Could not submit'))
                ->body($message)
                ->danger()
                ->send();
        }
    }
}
