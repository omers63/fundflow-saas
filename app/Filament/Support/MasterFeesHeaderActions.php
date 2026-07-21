<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\MasterFeeDeductionService;
use App\Services\MasterFeeDisbursementService;
use App\Services\MemberFeeArrearsService;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Auth;

/**
 * Header actions for the master fees account transaction table.
 */
final class MasterFeesHeaderActions
{
    /**
     * @param  Closure(): Account  $resolveAccount
     * @param  (Closure(): mixed)|null  $after
     * @return array<int, Action>
     */
    public static function make(Closure $resolveAccount, ?Closure $after = null): array
    {
        $deductFee = Action::make('deductFee')
            ->label(__('Deduct'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->color('success')
            ->visible(fn (): bool => self::isMasterFeesAdmin($resolveAccount))
            ->modalHeading(__('Deduct'))
            ->modalDescription(__('Debits member cash (and master cash), credits master fees, and applies the payment to subscription or late-fee arrears when fully covered.'))
            ->modalWidth('md')
            ->schema([
                MemberSelect::make('member_id')
                    ->required()
                    ->live()
                    ->afterStateUpdated(function ($state, callable $set, MemberFeeArrearsService $arrears): void {
                        if (! filled($state)) {
                            return;
                        }

                        $member = Member::query()->find($state);

                        if ($member === null) {
                            return;
                        }

                        $total = $arrears->totalFeeArrears($member);

                        if ($total > 0) {
                            $set('amount', $total);
                        }
                    }),
                Placeholder::make('arrears_outstanding')
                    ->label(__('Outstanding fee arrears'))
                    ->visible(fn (Get $get): bool => filled($get('member_id')))
                    ->content(function (Get $get, MemberFeeArrearsService $arrears): string {
                        $member = Member::query()->find($get('member_id'));

                        if ($member === null) {
                            return number_format(0, 2);
                        }

                        $currency = (string) Setting::get('general', 'currency', 'USD');

                        return MoneyDisplay::format($arrears->totalFeeArrears($member), $currency) ?? '';
                    }),
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
                    ->rows(2)
                    ->maxLength(500),
            ])
            ->action(function (array $data, Action $action, MasterFeeDeductionService $feeDeductions): void {
                $member = Member::query()->findOrFail($data['member_id']);

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $feeDeductions->deduct(
                            $member,
                            (float) $data['amount'],
                            (string) $data['description'],
                            Carbon::parse($data['transacted_at']),
                        ),
                        __('Fee deduction failed'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Fee deducted'))
                    ->success()
                    ->send();
            });

        $disburseFee = Action::make('disburseFee')
            ->label(__('Disburse'))
            ->icon(Heroicon::OutlinedArrowUpCircle)
            ->color('warning')
            ->visible(fn (): bool => self::isMasterFeesAdmin($resolveAccount))
            ->modalHeading(__('Disburse'))
            ->modalDescription(__('Debits master fees only, then creates a pending bank line to match when the payment appears on an imported statement.'))
            ->modalWidth('md')
            ->schema(self::disburseFormSchema())
            ->action(function (array $data, Action $action, MasterFeeDisbursementService $feeDisbursements) use ($resolveAccount): void {
                $account = $resolveAccount();

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $feeDisbursements->disburse(
                            $account,
                            (float) $data['amount'],
                            (string) $data['description'],
                            Carbon::parse($data['transacted_at']),
                        ),
                        __('Disbursement failed'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Disbursement posted'))
                    ->success()
                    ->send();
            });

        if ($after !== null) {
            $deductFee->after($after);
            $disburseFee->after($after);
        }

        return [$deductFee, $disburseFee];
    }

    /**
     * @return array<int, DateTimePicker|TextInput|Textarea>
     */
    private static function disburseFormSchema(): array
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
                ->required()
                ->rows(2)
                ->maxLength(500),
        ];
    }

    /**
     * @param  Closure(): Account  $resolveAccount
     */
    private static function isMasterFeesAdmin(Closure $resolveAccount): bool
    {
        if (! (bool) Auth::guard('tenant')->user()?->is_admin) {
            return false;
        }

        $account = $resolveAccount();

        return $account->is_master && $account->type === 'fees';
    }
}
