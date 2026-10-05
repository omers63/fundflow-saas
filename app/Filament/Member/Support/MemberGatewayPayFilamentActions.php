<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

use App\Models\Tenant\FundPosting;
use App\Models\Tenant\GatewayPayment;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Services\Gateway\GatewayDueResolver;
use App\Services\Gateway\GatewayPaymentService;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

final class MemberGatewayPayFilamentActions
{
    public static function payContribution(): Action
    {
        return self::payNowAction(
            name: 'payContributionGateway',
            resolve: fn (Member $member, GatewayDueResolver $dues): ?array => $dues->nextContributionDue($member),
        );
    }

    public static function payEmi(?Loan $loan = null): Action
    {
        return self::payNowAction(
            name: 'payEmiGateway',
            resolve: function (Member $member, GatewayDueResolver $dues) use ($loan): ?array {
                return $dues->nextEmiDue($loan, $member);
            },
            recordLoan: $loan,
        );
    }

    public static function payFeeArrears(): Action
    {
        return self::payNowAction(
            name: 'payFeeArrearsGateway',
            resolve: fn (Member $member, GatewayDueResolver $dues): ?array => $dues->feeArrearsDue($member),
        );
    }

    public static function payPendingDeposit(): Action
    {
        return self::payNowAction(
            name: 'payDepositGateway',
            resolve: function (Member $member, GatewayDueResolver $dues, ?Model $record): ?array {
                $posting = $record instanceof FundPosting ? $record : null;

                return $dues->openDepositDue($member, $posting);
            },
            forRecord: true,
        );
    }

    /**
     * @param  callable(Member, GatewayDueResolver, ?Model): ?array{
     *     purpose: string,
     *     amount: float,
     *     label: string,
     *     payable: ?Model
     * }  $resolve
     */
    private static function payNowAction(
        string $name,
        callable $resolve,
        ?Loan $recordLoan = null,
        bool $forRecord = false,
    ): Action {
        $action = Action::make($name)
            ->label(__('Pay now'))
            ->icon('heroicon-o-credit-card')
            ->color('success')
            ->visible(function (?Model $record = null) use ($resolve, $recordLoan): bool {
                $member = CurrentMember::get();

                if ($member === null || $member->status !== 'active') {
                    return false;
                }

                if ($recordLoan !== null && (int) $recordLoan->member_id !== (int) $member->id) {
                    return false;
                }

                if ($record instanceof Loan && (int) $record->member_id !== (int) $member->id) {
                    return false;
                }

                if ($record instanceof FundPosting && (int) $record->member_id !== (int) $member->id) {
                    return false;
                }

                $due = $resolve($member, app(GatewayDueResolver::class), $record);

                return $due !== null && $due['amount'] > 0.001;
            })
            ->requiresConfirmation()
            ->modalHeading(__('Pay now'))
            ->modalDescription(function (?Model $record = null) use ($resolve): string {
                $member = CurrentMember::get();

                if ($member === null) {
                    return '';
                }

                $due = $resolve($member, app(GatewayDueResolver::class), $record);

                if ($due === null) {
                    return __('Nothing is currently due.');
                }

                $driver = (string) config('gateway.driver', 'fake');

                if ($driver === GatewayPayment::PROVIDER_FAKE) {
                    return __('Pay :amount for :label using the test payment gateway.', [
                        'amount' => number_format($due['amount'], 2),
                        'label' => $due['label'],
                    ]);
                }

                return __('You will be redirected to pay :amount for :label.', [
                    'amount' => number_format($due['amount'], 2),
                    'label' => $due['label'],
                ]);
            })
            ->action(function (?Model $record = null) use ($resolve): void {
                $member = CurrentMember::get();

                if ($member === null) {
                    return;
                }

                $due = $resolve($member, app(GatewayDueResolver::class), $record);

                if ($due === null || $due['amount'] <= 0.001) {
                    Notification::make()
                        ->title(__('Nothing due'))
                        ->body(__('There is no outstanding amount to pay.'))
                        ->warning()
                        ->send();

                    return;
                }

                try {
                    $payment = app(GatewayPaymentService::class)->createIntent(
                        $member,
                        $due['purpose'],
                        $due['amount'],
                        expectedOwed: $due['amount'],
                        payable: $due['payable'],
                        metadata: ['label' => $due['label']],
                    );
                } catch (InvalidArgumentException $e) {
                    Notification::make()
                        ->title(__('Could not start payment'))
                        ->body($e->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                $driver = (string) config('gateway.driver', 'fake');

                if ($driver === GatewayPayment::PROVIDER_FAKE || filled($payment->checkout_url) === false) {
                    if ($payment->provider === GatewayPayment::PROVIDER_FAKE) {
                        app(GatewayPaymentService::class)->completeFake($payment);

                        Notification::make()
                            ->title(__('Payment completed'))
                            ->body(__('Your cash account was credited. Outstanding dues may be collected automatically.'))
                            ->success()
                            ->send();

                        return;
                    }
                }

                if (filled($payment->checkout_url)) {
                    redirect()->away($payment->checkout_url);

                    return;
                }

                Notification::make()
                    ->title(__('Payment started'))
                    ->body(__('Complete checkout with your payment provider. Reference: :ref', [
                        'ref' => $payment->provider_ref,
                    ]))
                    ->success()
                    ->send();
            });

        if ($forRecord) {
            return $action;
        }

        return $action;
    }
}
