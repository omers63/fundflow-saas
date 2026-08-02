<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

use App\Filament\Support\MemberFreezeFormSchema;
use App\Filament\Support\MemberWithdrawFormSchema;
use App\Filament\Support\TableHeaderIconAction;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Services\MemberFreezeService;
use App\Services\MemberWithdrawalSettlementService;
use App\Services\Tenant\MemberRequestService;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Validation\ValidationException;

final class MemberRequestFilamentActions
{
    /**
     * @return list<Action|ActionGroup>
     */
    public static function membershipHeaderActions(): array
    {
        $service = app(MemberRequestService::class);

        return [
            TableHeaderIconAction::applyGroup(
                ActionGroup::make([
                    self::freezeMembershipAction($service),
                    self::extendFreezeMembershipAction($service),
                    self::unfreezeMembershipAction($service),
                    self::withdrawMembershipAction($service),
                    self::independenceAction($service),
                ])
                    ->label(__('New request'))
                    ->icon('heroicon-o-plus')
                    ->color('primary')
            ),
        ];
    }

    private static function freezeMembershipAction(MemberRequestService $service): Action
    {
        $freezes = app(MemberFreezeService::class);

        return Action::make('requestFreezeMembership')
            ->label(__('Freeze membership'))
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->visible(fn (): bool => CurrentMember::get()?->status === 'active')
            ->modalHeading(__('Request membership freeze'))
            ->modalDescription(__('Plan how long to pause contributions. Cash-out stays frozen. Your portal becomes read-only until you are unfrozen.'))
            ->modalWidth(Width::TwoExtraLarge)
            ->extraModalWindowAttributes([
                'class' => 'ff-member-form-modal-window ff-freeze-modal',
            ])
            ->fillForm([
                'cycles' => 1,
                'household_mode' => MemberFreezeService::HOUSEHOLD_SELF_ONLY,
            ])
            ->schema(function () use ($freezes): array {
                $member = CurrentMember::get();

                if (! $member instanceof Member) {
                    return [];
                }

                return MemberFreezeFormSchema::requestWizard(
                    $member,
                    $freezes->blockingReasons($member),
                );
            })
            ->action(function (array $data) use ($service): void {
                self::submit($service, MemberRequest::TYPE_FREEZE_MEMBERSHIP, [
                    'cycles' => (int) ($data['cycles'] ?? 0),
                    'household_mode' => (string) ($data['household_mode'] ?? MemberFreezeService::HOUSEHOLD_SELF_ONLY),
                    'temporary_parent_member_id' => $data['temporary_parent_member_id'] ?? null,
                    'reason' => $data['reason'] ?? '',
                ]);
            });
    }

    private static function extendFreezeMembershipAction(MemberRequestService $service): Action
    {
        return Action::make('requestExtendFreezeMembership')
            ->label(__('Extend freeze'))
            ->icon('heroicon-o-forward')
            ->color('warning')
            ->visible(fn (): bool => CurrentMember::get()?->status === 'inactive'
                && CurrentMember::get()?->frozen_at !== null)
            ->modalHeading(__('Extend freeze plan'))
            ->modalDescription(__('Adds cycles to your freeze plan. Late fees stay paused until the extended plan ends.'))
            ->modalWidth(Width::Large)
            ->extraModalWindowAttributes([
                'class' => 'ff-member-form-modal-window ff-freeze-modal',
            ])
            ->fillForm(['cycles' => 1])
            ->schema([
                TextInput::make('cycles')
                    ->label(__('Additional cycles'))
                    ->numeric()
                    ->required()
                    ->minValue(MemberFreezeService::MIN_CYCLES)
                    ->maxValue(MemberFreezeService::MAX_CYCLES)
                    ->helperText(__('These cycles are added to your current freeze plan.')),
                Textarea::make('reason')
                    ->label(__('Reason (optional)'))
                    ->rows(3)
                    ->maxLength(500),
            ])
            ->requiresConfirmation()
            ->action(fn (array $data) => self::submit($service, MemberRequest::TYPE_EXTEND_FREEZE_MEMBERSHIP, [
                'cycles' => (int) ($data['cycles'] ?? 0),
                'reason' => $data['reason'] ?? '',
            ]));
    }

    private static function unfreezeMembershipAction(MemberRequestService $service): Action
    {
        return Action::make('requestUnfreezeMembership')
            ->label(__('Unfreeze membership'))
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->visible(fn (): bool => CurrentMember::get()?->status === 'inactive'
                && CurrentMember::get()?->frozen_at !== null)
            ->modalHeading(__('Request early unfreeze'))
            ->modalDescription(__('If approved before your plan ends, deferred EMIs are pulled back to their prior schedule.'))
            ->modalWidth(Width::Large)
            ->extraModalWindowAttributes([
                'class' => 'ff-member-form-modal-window ff-freeze-modal',
            ])
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
        $settlement = app(MemberWithdrawalSettlementService::class);

        return Action::make('requestWithdrawMembership')
            ->label(__('Leave fund'))
            ->icon('heroicon-o-arrow-right-on-rectangle')
            ->color('danger')
            ->visible(fn (): bool => in_array(CurrentMember::get()?->status, ['active', 'inactive'], true)
                && CurrentMember::get()?->frozen_at === null)
            ->modalHeading(__('Request to leave the fund'))
            ->modalDescription(__('Voluntary exit from the fund. Settle loans, clear guarantor roles, and choose how dependents are handled. Administration will review before membership ends.'))
            ->modalWidth(Width::TwoExtraLarge)
            ->extraModalWindowAttributes([
                'class' => 'ff-member-form-modal-window ff-freeze-modal',
            ])
            ->fillForm(function (): array {
                $member = CurrentMember::get();
                $hasDependents = $member instanceof Member
                    && $member->isParent()
                    && $member->dependents()->where('status', 'active')->exists();

                return [
                    'household_mode' => $hasDependents
                        ? MemberWithdrawalSettlementService::HOUSEHOLD_INCLUDE_DEPENDENTS
                        : MemberWithdrawalSettlementService::HOUSEHOLD_SELF_ONLY,
                ];
            })
            ->schema(function () use ($settlement): array {
                $member = CurrentMember::get();

                if (! $member instanceof Member) {
                    return [];
                }

                return MemberWithdrawFormSchema::requestWizard(
                    $member,
                    $settlement->blockingReasons($member),
                );
            })
            ->action(fn (array $data) => self::submit($service, MemberRequest::TYPE_WITHDRAW_MEMBERSHIP, [
                'reason' => $data['reason'] ?? '',
                'household_mode' => (string) ($data['household_mode'] ?? MemberWithdrawalSettlementService::HOUSEHOLD_SELF_ONLY),
                'permanent_parent_member_id' => $data['permanent_parent_member_id'] ?? null,
            ]));
    }

    private static function independenceAction(MemberRequestService $service): Action
    {
        return Action::make('requestIndependence')
            ->label(__('Become independent'))
            ->icon('heroicon-o-arrow-right-start-on-rectangle')
            ->color('warning')
            ->visible(fn (): bool => CurrentMember::get()?->parent_member_id !== null
                && CurrentMember::get()?->status === 'active')
            ->requiresConfirmation()
            ->modalHeading(__('Request independence'))
            ->modalDescription(__('Ask fund administrators to unlink you from your household parent. After approval, you will manage your own membership.'))
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
