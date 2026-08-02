<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

use App\Filament\Support\MoneyDisplay;
use App\Filament\Support\TableHeaderIconAction;
use App\Services\MemberCashOutService;
use App\Services\MemberFundOutService;
use App\Support\Insights\InsightFormatter;
use App\Support\MemberMembershipPolicy;
use App\Support\Tenant\CurrentMember;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\HtmlString;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class MemberWithdrawalFilamentActions
{
    /**
     * @return list<Action>
     */
    public static function headerActions(): array
    {
        return TableHeaderIconAction::applyMany([
            self::requestCashOut(),
            self::requestFundOut(),
        ]);
    }

    public static function requestCashOut(): Action
    {
        return MemberPortalViewModal::applyToForm(
            Action::make('requestCashOut')
                ->label(__('Request cash out'))
                ->icon('heroicon-o-arrow-up-tray')
                ->color('primary')
                ->visible(fn (): bool => self::canRequestWithdrawals())
                ->modalHeading(__('Request cash out'))
                ->modalDescription(__('Withdraw from your cash account to your registered bank account after admin approval.'))
                ->schema(self::cashOutSchema())
                ->action(function (array $data): void {
                    $member = CurrentMember::get();

                    if ($member === null) {
                        return;
                    }

                    try {
                        app(MemberCashOutService::class)->submit(
                            member: $member,
                            amount: (float) $data['amount'],
                            notes: $data['notes'] ?? null,
                        );
                    } catch (InvalidArgumentException $exception) {
                        throw ValidationException::withMessages([
                            'amount' => $exception->getMessage(),
                        ]);
                    }

                    Notification::make()
                        ->title(__('Cash-out submitted'))
                        ->body(__('Your request has been sent to the admin for review.'))
                        ->success()
                        ->send();
                })
        );
    }

    public static function requestFundOut(): Action
    {
        return MemberPortalViewModal::applyToForm(
            Action::make('requestFundOut')
                ->label(__('Request fund out'))
                ->icon('heroicon-o-arrow-right-circle')
                ->color('warning')
                ->visible(fn (): bool => self::canRequestWithdrawals())
                ->modalHeading(__('Request fund out'))
                ->modalDescription(__('Move money from your fund account to your cash account after admin approval. This does not send money to your bank.'))
                ->schema(self::fundOutSchema())
                ->action(function (array $data): void {
                    $member = CurrentMember::get();

                    if ($member === null) {
                        return;
                    }

                    try {
                        app(MemberFundOutService::class)->submit(
                            member: $member,
                            amount: (float) $data['amount'],
                            notes: $data['notes'] ?? null,
                        );
                    } catch (InvalidArgumentException $exception) {
                        throw ValidationException::withMessages([
                            'amount' => $exception->getMessage(),
                        ]);
                    }

                    Notification::make()
                        ->title(__('Fund-out submitted'))
                        ->body(__('Your request has been sent to the admin for review.'))
                        ->success()
                        ->send();
                })
        );
    }

    /**
     * @return list<Component>
     */
    public static function cashOutSchema(): array
    {
        return [
            Placeholder::make('cash_availability')
                ->label(__('Available to withdraw'))
                ->content(function (MemberCashOutService $service): HtmlString {
                    $member = CurrentMember::get();
                    $currency = InsightFormatter::currency();
                    $available = $member !== null ? $service->availableCashForWithdrawal($member) : 0.0;
                    $reserved = $member !== null ? $service->reservedForNextEmi($member) : 0.0;
                    $availableHtml = MoneyDisplay::html($available, $currency)?->toHtml() ?? '—';
                    $reservedHtml = MoneyDisplay::html($reserved, $currency)?->toHtml() ?? '—';

                    return new HtmlString(
                        '<div class="space-y-1 text-sm">'
                        .__('Available: :amount', ['amount' => $availableHtml])
                        .'<br><span class="text-gray-500">'
                        .__('Reserved for next EMI: :amount', ['amount' => $reservedHtml])
                        .'</span></div>'
                    );
                }),
            TextInput::make('amount')
                ->label(__('Withdrawal amount'))
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->rules([
                    fn (MemberCashOutService $service): Closure => function (string $attribute, mixed $value, Closure $fail) use ($service): void {
                        $member = CurrentMember::get();

                        if ($member === null) {
                            return;
                        }

                        $available = $service->availableCashForWithdrawal($member);

                        if ((float) $value > $available + 0.01) {
                            $fail(__('Amount exceeds available cash (:available).', [
                                'available' => number_format($available, 2),
                            ]));
                        }
                    },
                ])
                ->helperText(__('Cash out draws from your cash account, not your fund account.')),
            Textarea::make('notes')
                ->label(__('Notes'))
                ->rows(3)
                ->maxLength(1000)
                ->placeholder(__('Optional instructions for the admin...')),
        ];
    }

    /**
     * @return list<Component>
     */
    public static function fundOutSchema(): array
    {
        return [
            Placeholder::make('fund_availability')
                ->label(__('Available fund balance'))
                ->content(function (MemberFundOutService $service): HtmlString {
                    $member = CurrentMember::get();
                    $currency = InsightFormatter::currency();
                    $available = $member !== null ? $service->availableFundForTransfer($member) : 0.0;
                    $availableHtml = MoneyDisplay::html($available, $currency)?->toHtml() ?? '—';

                    return new HtmlString(
                        '<div class="space-y-1 text-sm">'
                        .__('Available: :amount', ['amount' => $availableHtml])
                        .'</div>'
                    );
                }),
            TextInput::make('amount')
                ->label(__('Transfer amount'))
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->rules([
                    fn (MemberFundOutService $service): Closure => function (string $attribute, mixed $value, Closure $fail) use ($service): void {
                        $member = CurrentMember::get();

                        if ($member === null) {
                            return;
                        }

                        $available = $service->availableFundForTransfer($member);

                        if ((float) $value > $available + 0.01) {
                            $fail(__('Amount exceeds available fund balance (:available).', [
                                'available' => number_format($available, 2),
                            ]));
                        }
                    },
                ])
                ->helperText(__('After approval, the amount moves from your fund account to your cash account. Use Cash out to send cash to your bank.')),
            Textarea::make('notes')
                ->label(__('Notes'))
                ->rows(3)
                ->maxLength(1000)
                ->placeholder(__('Optional instructions for the admin...')),
        ];
    }

    private static function canRequestWithdrawals(): bool
    {
        $member = CurrentMember::get();

        if ($member === null) {
            return false;
        }

        return app(MemberMembershipPolicy::class)->canRequestCashOut($member);
    }
}
