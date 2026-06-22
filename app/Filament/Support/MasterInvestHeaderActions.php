<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Account;
use App\Services\MasterInvestInService;
use App\Services\MasterInvestOutService;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Auth;

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
        $investOut = Action::make('investOut')
            ->label(__('Invest Out'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('warning')
            ->visible(fn (): bool => self::isMasterInvestAdmin($resolveAccount))
            ->modalHeading(__('Invest Out'))
            ->modalDescription(__('Transfers funds from Master Fund into Master Invest, then debits Master Invest and creates a pending bank line to match when the payment appears on an imported statement.'))
            ->modalWidth('md')
            ->schema(self::formSchema())
            ->action(function (array $data, Action $action, MasterInvestOutService $investOutService) use ($resolveAccount): void {
                $account = $resolveAccount();

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $investOutService->investOut(
                            $account,
                            (float) $data['amount'],
                            (string) $data['description'],
                            Carbon::parse($data['transacted_at']),
                        ),
                        __('Invest out failed'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Invest out posted'))
                    ->success()
                    ->send();
            });

        $investIn = Action::make('investIn')
            ->label(__('Invest In'))
            ->icon('heroicon-o-arrow-down-tray')
            ->color('success')
            ->visible(fn (): bool => self::isMasterInvestAdmin($resolveAccount))
            ->modalHeading(__('Invest In'))
            ->modalDescription(__('Credits master invest, transfers the return to master fund, then creates a pending bank line to match when the receipt appears on an imported statement.'))
            ->modalWidth('md')
            ->schema(self::formSchema(__('Investment return')))
            ->action(function (array $data, Action $action, MasterInvestInService $investInService) use ($resolveAccount): void {
                $account = $resolveAccount();

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $investInService->investIn(
                            $account,
                            (float) $data['amount'],
                            (string) $data['description'],
                            Carbon::parse($data['transacted_at']),
                        ),
                        __('Invest in failed'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Invest in posted'))
                    ->success()
                    ->send();
            });

        if ($after !== null) {
            $investOut->after($after);
            $investIn->after($after);
        }

        return [$investOut, $investIn];
    }

    /**
     * @param  Closure(): Account  $resolveAccount
     */
    private static function isMasterInvestAdmin(Closure $resolveAccount): bool
    {
        if (! (bool) Auth::guard('tenant')->user()?->is_admin) {
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
                ->default($defaultDescription)
                ->required()
                ->rows(2)
                ->maxLength(500),
        ];
    }
}
