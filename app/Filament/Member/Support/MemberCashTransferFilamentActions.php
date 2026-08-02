<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

use App\Filament\Member\Resources\MyCashTransfers\Schemas\MyCashTransferForm;
use App\Models\Tenant\Member;
use App\Models\Tenant\MemberCashTransferRequest;
use App\Services\MemberCashTransferService;
use App\Support\MemberMembershipPolicy;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final class MemberCashTransferFilamentActions
{
    public static function requestTransfer(): Action
    {
        return Action::make('requestCashTransfer')
            ->label(__('Request transfer'))
            ->icon('heroicon-o-arrows-right-left')
            ->color('primary')
            ->visible(fn (): bool => self::canSubmit())
            ->modalHeading(__('Request cash transfer'))
            ->modalDescription(__('Transfer cash to a dependent instantly, or request a transfer to another member for admin approval.'))
            ->modalWidth(Width::Large)
            ->schema(MyCashTransferForm::components())
            ->action(function (array $data): void {
                $member = CurrentMember::get();

                if ($member === null) {
                    return;
                }

                $service = app(MemberCashTransferService::class);

                try {
                    if (($data['transfer_mode'] ?? 'other') === 'dependent') {
                        $dependent = Member::query()->findOrFail((int) $data['to_member_id']);
                        $request = $service->transferToDependent(
                            parent: $member,
                            dependent: $dependent,
                            amount: (float) $data['amount'],
                            notes: $data['notes'] ?? null,
                        );
                    } else {
                        $request = $service->submit(
                            from: $member,
                            amount: (float) $data['amount'],
                            recipientName: (string) $data['recipient_name'],
                            notes: $data['notes'] ?? null,
                        );
                    }
                } catch (InvalidArgumentException|RuntimeException $exception) {
                    throw ValidationException::withMessages([
                        'amount' => $exception->getMessage(),
                    ]);
                }

                self::notifySubmitted($request);
            });
    }

    private static function notifySubmitted(MemberCashTransferRequest $request): void
    {
        if ($request->status === 'accepted') {
            Notification::make()
                ->title(__('Cash transfer completed'))
                ->body(__('Cash moved to the dependent immediately.'))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('Cash transfer submitted'))
            ->body(__('Your request has been sent to the admin for review.'))
            ->success()
            ->send();
    }

    private static function canSubmit(): bool
    {
        $member = CurrentMember::get();

        if ($member === null) {
            return false;
        }

        return app(MemberMembershipPolicy::class)->canRequestCashOut($member);
    }
}
