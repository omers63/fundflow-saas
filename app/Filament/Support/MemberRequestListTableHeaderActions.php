<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\MemberRequests\MemberRequestResource;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberRequest;
use App\Services\Tenant\MemberRequestService;
use Filament\Actions\Action;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Validation\ValidationException;
use Livewire\Component;

final class MemberRequestListTableHeaderActions
{
    /**
     * @return list<Action>
     */
    public static function all(): array
    {
        return [
            self::newRequestAction(),
        ];
    }

    public static function newRequestAction(): Action
    {
        return Action::make('newRequest')
            ->label(__('New request'))
            ->icon('heroicon-o-plus-circle')
            ->color('primary')
            ->modalHeading(__('New request'))
            ->modalDescription(__('File a membership request on behalf of a member. The member is notified after review.'))
            ->modalSubmitActionLabel(__('Submit request'))
            ->schema([
                Select::make('requester_member_id')
                    ->label(__('Member'))
                    ->options(fn (): array => Member::query()
                        ->orderBy('member_number')
                        ->get(['id', 'member_number', 'name'])
                        ->mapWithKeys(fn (Member $member): array => [
                            $member->id => "{$member->member_number} — {$member->name}",
                        ])
                        ->all())
                    ->searchable()
                    ->required(),
                Select::make('type')
                    ->label(__('Request'))
                    ->options([
                        MemberRequest::TYPE_FREEZE_MEMBERSHIP => MemberRequest::typeLabel(MemberRequest::TYPE_FREEZE_MEMBERSHIP),
                        MemberRequest::TYPE_UNFREEZE_MEMBERSHIP => MemberRequest::typeLabel(MemberRequest::TYPE_UNFREEZE_MEMBERSHIP),
                        MemberRequest::TYPE_WITHDRAW_MEMBERSHIP => MemberRequest::typeLabel(MemberRequest::TYPE_WITHDRAW_MEMBERSHIP),
                        MemberRequest::TYPE_REINSTATE_MEMBERSHIP => MemberRequest::typeLabel(MemberRequest::TYPE_REINSTATE_MEMBERSHIP),
                        MemberRequest::TYPE_RELEASE_PAYOUT => MemberRequest::typeLabel(MemberRequest::TYPE_RELEASE_PAYOUT),
                        MemberRequest::TYPE_REQUEST_INDEPENDENCE => MemberRequest::typeLabel(MemberRequest::TYPE_REQUEST_INDEPENDENCE),
                        MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION => MemberRequest::typeLabel(MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION),
                    ])
                    ->required()
                    ->live(),
                TextInput::make('requested_amount')
                    ->label(__('Requested amount'))
                    ->numeric()
                    ->minValue(0.01)
                    ->visible(fn (Get $get): bool => $get('type') === MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION)
                    ->required(fn (Get $get): bool => $get('type') === MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION),
                Textarea::make('reason')
                    ->label(__('Reason (optional)'))
                    ->rows(3)
                    ->maxLength(500)
                    ->visible(fn (Get $get): bool => in_array($get('type'), [
                        MemberRequest::TYPE_FREEZE_MEMBERSHIP,
                        MemberRequest::TYPE_UNFREEZE_MEMBERSHIP,
                        MemberRequest::TYPE_WITHDRAW_MEMBERSHIP,
                        MemberRequest::TYPE_REINSTATE_MEMBERSHIP,
                        MemberRequest::TYPE_RELEASE_PAYOUT,
                        MemberRequest::TYPE_REQUEST_INDEPENDENCE,
                    ], true)),
            ])
            ->action(function (array $data, Component $livewire): void {
                $member = Member::query()->find((int) ($data['requester_member_id'] ?? 0));

                if ($member === null) {
                    Notification::make()->title(__('Member not found'))->danger()->send();

                    return;
                }

                $type = (string) ($data['type'] ?? '');
                $payload = match ($type) {
                    MemberRequest::TYPE_OPEN_CYCLE_CONTRIBUTION => [
                        'requested_amount' => (float) ($data['requested_amount'] ?? 0),
                    ],
                    default => [
                        'reason' => (string) ($data['reason'] ?? ''),
                    ],
                };

                try {
                    app(MemberRequestService::class)->submit($member, $type, $payload);
                } catch (ValidationException $exception) {
                    Notification::make()
                        ->title(__('Could not submit'))
                        ->body(collect($exception->errors())->flatten()->first() ?: __('Validation failed.'))
                        ->danger()
                        ->send();

                    return;
                }

                $livewire->resetTable();
                MemberRequestResource::dispatchInsightsRefresh($livewire);

                Notification::make()
                    ->title(__('Request submitted'))
                    ->success()
                    ->send();
            });
    }
}
