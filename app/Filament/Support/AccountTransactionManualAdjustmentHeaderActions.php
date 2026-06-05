<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Account;
use App\Models\Tenant\Setting;
use App\Services\AccountingService;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\HtmlString;

/**
 * Header actions for account transaction tables: post a manual ledger credit or debit on the account.
 */
final class AccountTransactionManualAdjustmentHeaderActions
{
    /**
     * @param  Closure(): Account  $resolveAccount
     * @param  (Closure(): mixed)|null  $after
     * @return array<int, Action>
     */
    public static function make(Closure $resolveAccount, ?Closure $after = null): array
    {
        $credit = Action::make('manualCredit')
            ->label(__('Credit'))
            ->icon('heroicon-o-arrow-trending-up')
            ->color('success')
            ->visible(fn(): bool => (bool) Auth::guard('tenant')->user()?->is_admin)
            ->modalHeading(__('Manual credit'))
            ->modalDescription(fn(): string => self::creditModalDescription($resolveAccount()))
            ->modalWidth('md')
            ->schema(fn(): array => self::formSchema($resolveAccount))
            ->action(function (array $data, Action $action, AccountingService $accounting) use ($resolveAccount): void {
                $account = $resolveAccount();

                if (
                    !ActionModalFailure::attemptThrowable(
                        $action,
                        fn() => $accounting->postManualCredit(
                            $account,
                            (float) $data['amount'],
                            (string) $data['description'],
                            Carbon::parse($data['transacted_at']),
                            filled($data['member_id'] ?? null) ? (int) $data['member_id'] : null,
                        ),
                        __('Credit failed'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Credit posted'))
                    ->success()
                    ->send();
            });

        $debit = Action::make('manualDebit')
            ->label(__('Debit'))
            ->icon('heroicon-o-arrow-trending-down')
            ->color('danger')
            ->visible(fn(): bool => (bool) Auth::guard('tenant')->user()?->is_admin)
            ->modalHeading(__('Manual debit'))
            ->modalDescription(fn(): string => self::debitModalDescription($resolveAccount()))
            ->modalWidth('md')
            ->schema(fn(): array => self::formSchema($resolveAccount))
            ->action(function (array $data, Action $action, AccountingService $accounting) use ($resolveAccount): void {
                $account = $resolveAccount();

                if (
                    !ActionModalFailure::attemptThrowable(
                        $action,
                        fn() => $accounting->postManualDebit(
                            $account,
                            (float) $data['amount'],
                            (string) $data['description'],
                            Carbon::parse($data['transacted_at']),
                            filled($data['member_id'] ?? null) ? (int) $data['member_id'] : null,
                        ),
                        __('Debit failed'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Debit posted'))
                    ->success()
                    ->send();
            });

        $refund = Action::make('refundMemberCash')
            ->label(__('Refund'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(function () use ($resolveAccount): bool {
                if (!(bool) Auth::guard('tenant')->user()?->is_admin) {
                    return false;
                }

                $account = $resolveAccount();

                return !$account->is_master && $account->type === 'cash';
            })
            ->modalHeading(__('Post refund'))
            ->modalDescription(__('Debits this member cash account and master cash, recording money returned to the member. The matching debit should appear on a future imported bank statement.'))
            ->modalSubmitActionLabel(__('Post refund'))
            ->modalWidth('md')
            ->schema(fn(): array => self::refundFormSchema($resolveAccount))
            ->action(function (array $data, Action $action, AccountingService $accounting) use ($resolveAccount): void {
                $account = $resolveAccount();

                if (
                    !ActionModalFailure::attemptThrowable(
                        $action,
                        fn() => $accounting->refundMemberCash(
                            $account,
                            (float) $data['amount'],
                            (string) $data['description'],
                            Carbon::parse($data['transacted_at']),
                        ),
                        __('Refund failed'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Refund posted'))
                    ->body(__('Refund of :amount posted for :name', [
                        'amount' => number_format((float) $data['amount'], 2) . ' ' . Setting::get('general', 'currency', 'USD'),
                        'name' => $account->member?->name ?? __('Member'),
                    ]))
                    ->success()
                    ->send();
            });

        if ($after !== null) {
            $credit->after($after);
            $debit->after($after);
            $refund->after($after);
        }

        return [$credit, $debit, $refund];
    }

    private static function creditModalDescription(Account $account): string
    {
        return __('Post a credit to this account only. Use a clear description for the audit trail.');
    }

    private static function debitModalDescription(Account $account): string
    {
        if (!$account->is_master && $account->type === 'fund') {
            return __('Post a debit to this member fund account only. The balance may go negative for adjustments such as loan allocation. Use a clear description for the audit trail.');
        }

        return __('Post a debit to this account only. Use a clear description for the audit trail.');
    }

    /**
     * @param  Closure(): Account  $resolveAccount
     * @return array<int, DateTimePicker|TextInput|Textarea|Select>
     */
    private static function formSchema(Closure $resolveAccount): array
    {
        $account = $resolveAccount();

        $fields = [
            DateTimePicker::make('transacted_at')
                ->label(__('Transaction date & time'))
                ->default(BusinessDay::now())
                ->required()
                ->native(false)
                ->seconds(true),
            TextInput::make('amount')
                ->label(__('Amount'))
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->step(0.01),
            Textarea::make('description')
                ->label(__('Description'))
                ->required()
                ->rows(3),
        ];

        if ($account->is_master) {
            $fields[] = MemberLedgerTagSelect::make()
                ->helperText(__('Optional — tags this ledger line with a member for reporting.'));
        }

        return $fields;
    }

    /**
     * @param  Closure(): Account  $resolveAccount
     * @return array<int, Placeholder|DateTimePicker|TextInput|Textarea>
     */
    private static function refundFormSchema(Closure $resolveAccount): array
    {
        $account = $resolveAccount();
        $balance = (float) $account->balance;
        $currency = Setting::get('general', 'currency', 'USD');
        $balanceClass = $balance > 0 ? 'text-success-600 dark:text-success-400' : 'text-danger-600 dark:text-danger-400';

        return [
            Placeholder::make('balance_info')
                ->label(__('Available balance'))
                ->content(new HtmlString(
                    '<span class="text-lg font-bold ' . $balanceClass . '">'
                    . e(number_format($balance, 2) . ' ' . $currency)
                    . '</span>'
                )),
            DateTimePicker::make('transacted_at')
                ->label(__('Transaction date & time'))
                ->default(BusinessDay::now())
                ->required()
                ->native(false)
                ->seconds(true),
            TextInput::make('amount')
                ->label(__('Amount'))
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->maxValue($balance > 0 ? $balance : null)
                ->default($balance > 0 ? $balance : null)
                ->step(0.01),
            Textarea::make('description')
                ->label(__('Reason / description'))
                ->required()
                ->rows(2)
                ->maxLength(500),
        ];
    }
}
