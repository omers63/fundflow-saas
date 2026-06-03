<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Account;
use App\Services\AccountingService;
use App\Services\MasterInvestDisbursementService;
use App\Services\MasterInvestReturnService;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;
use Throwable;

/**
 * Header actions for the master invest account transaction table.
 */
final class MasterInvestHeaderActions
{
    /**
     * @param  Closure(): Account  $resolveAccount
     * @param  (Closure(): mixed)|null  $after
     * @return array<int, Action>
     */
    public static function make(Closure $resolveAccount, ?Closure $after = null): array
    {
        $fundInvest = Action::make('fundInvest')
            ->label(__('Fund Invest'))
            ->icon('heroicon-o-arrow-down-circle')
            ->color('success')
            ->visible(fn(): bool => self::isMasterInvestAdmin($resolveAccount))
            ->modalHeading(__('Fund Invest'))
            ->modalDescription(__('Transfer funds from Master Fund into the Master Invest account.'))
            ->modalWidth('md')
            ->schema(self::formSchema(__('Invest funding from master fund')))
            ->action(function (array $data, AccountingService $accounting) use ($resolveAccount): void {
                $account = $resolveAccount();

                try {
                    $accounting->fundReserveAccountFromMasterFund(
                        $account,
                        (float) $data['amount'],
                        (string) $data['description'],
                        Carbon::parse($data['transacted_at']),
                    );
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title(__('Funding failed'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Funding posted'))
                    ->success()
                    ->send();
            });

        $disburseInvest = Action::make('disburseInvest')
            ->label(__('Disburse Invest'))
            ->icon('heroicon-o-arrow-up-circle')
            ->color('warning')
            ->visible(fn(): bool => self::isMasterInvestAdmin($resolveAccount))
            ->modalHeading(__('Disburse Invest'))
            ->modalDescription(__('Debits master invest only, then creates a pending bank line to match when the payment appears on an imported statement.'))
            ->modalWidth('md')
            ->schema(self::formSchema())
            ->action(function (array $data, MasterInvestDisbursementService $investDisbursements) use ($resolveAccount): void {
                $account = $resolveAccount();

                try {
                    $investDisbursements->disburse(
                        $account,
                        (float) $data['amount'],
                        (string) $data['description'],
                        Carbon::parse($data['transacted_at']),
                    );
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title(__('Disbursement failed'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Disbursement posted'))
                    ->success()
                    ->send();
            });

        $recordReturn = Action::make('recordReturn')
            ->label(__('Record Return'))
            ->icon('heroicon-o-arrow-path-rounded-square')
            ->color('info')
            ->visible(fn(): bool => self::isMasterInvestAdmin($resolveAccount))
            ->modalHeading(__('Record Return'))
            ->modalDescription(__('Credits master invest only, then creates a pending bank line to match when the receipt appears on an imported statement.'))
            ->modalWidth('md')
            ->schema(self::formSchema(__('Investment return')))
            ->action(function (array $data, MasterInvestReturnService $investReturns) use ($resolveAccount): void {
                $account = $resolveAccount();

                try {
                    $investReturns->record(
                        $account,
                        (float) $data['amount'],
                        (string) $data['description'],
                        Carbon::parse($data['transacted_at']),
                    );
                } catch (Throwable $exception) {
                    Notification::make()
                        ->title(__('Return posting failed'))
                        ->body($exception->getMessage())
                        ->danger()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Investment return recorded'))
                    ->success()
                    ->send();
            });

        if ($after !== null) {
            $fundInvest->after($after);
            $disburseInvest->after($after);
            $recordReturn->after($after);
        }

        return [$fundInvest, $disburseInvest, $recordReturn];
    }

    /**
     * @param  Closure(): Account  $resolveAccount
     */
    private static function isMasterInvestAdmin(Closure $resolveAccount): bool
    {
        if (!(bool) Auth::guard('tenant')->user()?->is_admin) {
            return false;
        }

        $account = $resolveAccount();

        return $account->is_master && $account->type === 'invest';
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
