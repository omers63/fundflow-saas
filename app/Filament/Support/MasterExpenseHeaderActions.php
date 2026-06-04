<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Account;
use App\Services\AccountingService;
use App\Services\MasterExpenseDisbursementService;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

/**
 * Header actions for the master expense account transaction table.
 */
final class MasterExpenseHeaderActions
{
    /**
     * @param  Closure(): Account  $resolveAccount
     * @param  (Closure(): mixed)|null  $after
     * @return array<int, Action>
     */
    public static function make(Closure $resolveAccount, ?Closure $after = null): array
    {
        $fundExpense = Action::make('fundExpense')
            ->label(__('Fund Expense'))
            ->icon('heroicon-o-arrow-down-circle')
            ->color('success')
            ->visible(function () use ($resolveAccount): bool {
                if (! (bool) Auth::guard('tenant')->user()?->is_admin) {
                    return false;
                }

                $account = $resolveAccount();

                return $account->is_master && $account->type === 'expense';
            })
            ->modalHeading(__('Fund Expense'))
            ->modalDescription(__('Transfer funds from Master Fund into the Master Expense account.'))
            ->modalWidth('md')
            ->schema(self::formSchema(__('Expense funding from master fund')))
            ->action(function (array $data, Action $action, AccountingService $accounting) use ($resolveAccount): void {
                $account = $resolveAccount();

                if (! ActionModalFailure::attemptThrowable(
                    $action,
                    fn () => $accounting->fundReserveAccountFromMasterFund(
                        $account,
                        (float) $data['amount'],
                        (string) $data['description'],
                        Carbon::parse($data['transacted_at']),
                    ),
                    __('Funding failed'),
                )) {
                    return;
                }

                Notification::make()
                    ->title(__('Funding posted'))
                    ->success()
                    ->send();
            });

        $disburseExpense = Action::make('disburseExpense')
            ->label(__('Disburse Expense'))
            ->icon('heroicon-o-arrow-up-circle')
            ->color('warning')
            ->visible(function () use ($resolveAccount): bool {
                if (! (bool) Auth::guard('tenant')->user()?->is_admin) {
                    return false;
                }

                $account = $resolveAccount();

                return $account->is_master && $account->type === 'expense';
            })
            ->modalHeading(__('Disburse Expense'))
            ->modalDescription(__('Debits master expense only, then creates a pending bank line to match when the payment appears on an imported statement.'))
            ->modalWidth('md')
            ->schema(self::formSchema())
            ->action(function (array $data, Action $action, MasterExpenseDisbursementService $expenseDisbursements) use ($resolveAccount): void {
                $account = $resolveAccount();

                if (! ActionModalFailure::attemptThrowable(
                    $action,
                    fn () => $expenseDisbursements->disburse(
                        $account,
                        (float) $data['amount'],
                        (string) $data['description'],
                        Carbon::parse($data['transacted_at']),
                    ),
                    __('Disbursement failed'),
                )) {
                    return;
                }

                Notification::make()
                    ->title(__('Disbursement posted'))
                    ->success()
                    ->send();
            });

        if ($after !== null) {
            $fundExpense->after($after);
            $disburseExpense->after($after);
        }

        return [$fundExpense, $disburseExpense];
    }

    /**
     * @return array<int, DateTimePicker|TextInput|Textarea>
     */
    private static function formSchema(?string $defaultDescription = null): array
    {
        return [
            DateTimePicker::make('transacted_at')
                ->label(__('Transaction date & time'))
                ->default(now())
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
                ->default($defaultDescription)
                ->required()
                ->rows(2)
                ->maxLength(500),
        ];
    }
}
