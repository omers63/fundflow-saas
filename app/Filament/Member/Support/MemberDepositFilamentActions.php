<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

use App\Filament\Member\Resources\MyFundPostings\Schemas\MyFundPostingForm;
use App\Models\Tenant\FundPosting;
use App\Services\FundPostingService;
use App\Support\MemberMembershipPolicy;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use RuntimeException;

final class MemberDepositFilamentActions
{
    public static function requestDeposit(): Action
    {
        return MemberPortalViewModal::applyToForm(
            Action::make('requestDeposit')
                ->label(__('New deposit'))
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->visible(fn (): bool => self::canSubmit())
                ->modalHeading(__('New deposit'))
                ->modalDescription(__('Submit a bank deposit for admin review. Accepted deposits credit your cash account.'))
                ->schema(MyFundPostingForm::components())
                ->action(function (array $data): void {
                    $member = CurrentMember::get();

                    if ($member === null) {
                        return;
                    }

                    try {
                        $posting = app(FundPostingService::class)->submit(
                            member: $member,
                            amount: (float) $data['amount'],
                            postingDate: $data['posting_date'],
                            reference: $data['reference'] ?? null,
                            attachment: $data['attachment'] ?? null,
                            comments: $data['comments'] ?? null,
                        );
                    } catch (InvalidArgumentException|RuntimeException $exception) {
                        throw ValidationException::withMessages([
                            'amount' => $exception->getMessage(),
                        ]);
                    }

                    self::notifySubmitted($posting);
                })
        );
    }

    private static function notifySubmitted(FundPosting $posting): void
    {
        if ($posting->status === 'accepted') {
            Notification::make()
                ->title(__('Deposit accepted'))
                ->body(__('Your deposit was accepted and credited to your cash account.'))
                ->success()
                ->send();

            return;
        }

        Notification::make()
            ->title(__('Deposit submitted'))
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

        return app(MemberMembershipPolicy::class)->canMutatePortal($member);
    }
}
