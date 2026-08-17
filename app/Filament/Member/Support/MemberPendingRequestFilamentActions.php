<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

use App\Filament\Support\ActionModalFailure;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\FundOutRequest;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\MemberCashTransferRequest;
use App\Services\FundPostingService;
use App\Services\MemberCashOutService;
use App\Services\MemberCashTransferService;
use App\Services\MemberFundOutService;
use App\Support\Tenant\CurrentMember;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class MemberPendingRequestFilamentActions
{
    public static function cancelDeposit(): Action
    {
        return self::cancelAction(
            visible: fn (FundPosting $record): bool => $record->status === 'pending',
            run: function (FundPosting $record): void {
                app(FundPostingService::class)->cancel($record, auth('tenant')->id());
            },
            successTitle: __('Deposit cancelled'),
        );
    }

    public static function cancelSelectedDeposits(): BulkAction
    {
        return self::cancelSelectedAction(
            fn (FundPosting $record): bool => $record->status === 'pending',
            function (FundPosting $record): void {
                app(FundPostingService::class)->cancel($record, auth('tenant')->id());
            },
        );
    }

    public static function cancelCashOut(): Action
    {
        return self::cancelAction(
            visible: fn (CashOutRequest $record): bool => $record->status === 'pending',
            run: function (CashOutRequest $record): void {
                app(MemberCashOutService::class)->cancel($record, auth('tenant')->id());
            },
            successTitle: __('Cash-out cancelled'),
        );
    }

    public static function cancelSelectedCashOuts(): BulkAction
    {
        return self::cancelSelectedAction(
            fn (CashOutRequest $record): bool => $record->status === 'pending',
            function (CashOutRequest $record): void {
                app(MemberCashOutService::class)->cancel($record, auth('tenant')->id());
            },
        );
    }

    public static function cancelFundOut(): Action
    {
        return self::cancelAction(
            visible: fn (FundOutRequest $record): bool => $record->status === 'pending',
            run: function (FundOutRequest $record): void {
                app(MemberFundOutService::class)->cancel($record, auth('tenant')->id());
            },
            successTitle: __('Fund-out cancelled'),
        );
    }

    public static function cancelSelectedFundOuts(): BulkAction
    {
        return self::cancelSelectedAction(
            fn (FundOutRequest $record): bool => $record->status === 'pending',
            function (FundOutRequest $record): void {
                app(MemberFundOutService::class)->cancel($record, auth('tenant')->id());
            },
        );
    }

    public static function cancelCashTransfer(): Action
    {
        return self::cancelAction(
            visible: fn (MemberCashTransferRequest $record): bool => $record->status === 'pending'
                && (int) $record->from_member_id === (int) (CurrentMember::id() ?? 0),
            run: function (MemberCashTransferRequest $record): void {
                if ((int) $record->from_member_id !== (int) (CurrentMember::id() ?? 0)) {
                    throw new InvalidArgumentException(__('Only the sender can cancel this transfer.'));
                }

                app(MemberCashTransferService::class)->cancel($record, auth('tenant')->id());
            },
            successTitle: __('Cash transfer cancelled'),
        );
    }

    public static function cancelSelectedCashTransfers(): BulkAction
    {
        $memberId = fn (): int => (int) (CurrentMember::id() ?? 0);

        return self::cancelSelectedAction(
            fn (MemberCashTransferRequest $record): bool => $record->status === 'pending'
                && (int) $record->from_member_id === $memberId(),
            function (MemberCashTransferRequest $record) use ($memberId): void {
                if ((int) $record->from_member_id !== $memberId()) {
                    throw new InvalidArgumentException(__('Only the sender can cancel this transfer.'));
                }

                app(MemberCashTransferService::class)->cancel($record, auth('tenant')->id());
            },
        );
    }

    /**
     * @param  Closure(Model): bool  $visible
     * @param  Closure(Model): void  $run
     */
    private static function cancelAction(Closure $visible, Closure $run, string $successTitle): Action
    {
        return Action::make('cancel')
            ->label(__('Cancel'))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible($visible)
            ->requiresConfirmation()
            ->modalHeading(__('Cancel request'))
            ->modalDescription(__('This pending request will be withdrawn and will no longer wait for admin review.'))
            ->action(function (Model $record, Action $action) use ($run, $successTitle): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        function () use ($run, $record): void {
                            $run($record);
                        },
                        __('Cannot cancel'),
                    )
                ) {
                    return;
                }

                Notification::make()->title($successTitle)->success()->send();
            });
    }

    /**
     * @param  Closure(Model): bool  $eligible
     * @param  Closure(Model): void  $run
     */
    private static function cancelSelectedAction(Closure $eligible, Closure $run): BulkAction
    {
        return BulkAction::make('cancelSelected')
            ->label(__('Cancel selected'))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Cancel selected requests'))
            ->modalDescription(__('Pending requests will be withdrawn and will no longer wait for admin review.'))
            ->deselectRecordsAfterCompletion()
            ->action(function (Collection $records) use ($eligible, $run): void {
                $count = 0;

                foreach ($records as $record) {
                    if (! $eligible($record)) {
                        continue;
                    }

                    $run($record);
                    $count++;
                }

                Notification::make()
                    ->title(trans_choice(
                        ':count request cancelled|:count requests cancelled',
                        $count,
                        ['count' => $count],
                    ))
                    ->success()
                    ->send();
            });
    }
}
