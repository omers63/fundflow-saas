<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanEarlySettlementService;
use App\Services\Loans\LoanRepaymentService;
use App\Services\LoanService;
use App\Support\Lang;
use App\Support\Tenant\CurrentMember;
use Filament\Actions\Action;
use Filament\Forms\Components\Component;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Support\HtmlString;

final class MemberLoanFilamentActions
{
    public static function payOpenPeriodRepayment(): Action
    {
        return Action::make('payOpenPeriodRepayment')
            ->label(__('Pay this period'))
            ->icon('heroicon-o-currency-dollar')
            ->color('primary')
            ->visible(function (Loan $record): bool {
                $member = CurrentMember::get();

                return $member !== null
                    && (int) $record->member_id === (int) $member->id
                    && $record->status === 'active'
                    && app(LoanRepaymentService::class)->shouldOfferOpenPeriodRepayment($member);
            })
            ->requiresConfirmation()
            ->modalHeading(__('Pay loan installment from cash'))
            ->modalDescription(function (Loan $record): string {
                $member = CurrentMember::get();

                return $member
                    ? app(LoanRepaymentService::class)->openPeriodRepaymentModalDescription($member)
                    : '';
            })
            ->action(function (Loan $record): void {
                $member = CurrentMember::get();

                if ($member === null || (int) $record->member_id !== (int) $member->id) {
                    return;
                }

                $result = app(LoanRepaymentService::class)->applyOpenPeriodRepaymentForMember($member);

                $repayments = app(LoanRepaymentService::class);

                $notification = Notification::make()
                    ->title(match ($result) {
                        'applied' => __('Payment applied'),
                        'insufficient' => __('Insufficient cash balance'),
                        default => __('Nothing to pay'),
                    })
                    ->body($result === 'skipped'
                        ? $repayments->openPeriodSkipMessage($member)
                        : null);

                match ($result) {
                    'applied' => $notification->success(),
                    'insufficient' => $notification->warning(),
                    default => $notification->info(),
                };

                $notification->send();
            });
    }

    public static function earlySettle(): Action
    {
        return Action::make('earlySettle')
            ->label(__('Early settlement'))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(function (?Loan $record): bool {
                if (! $record instanceof Loan) {
                    return false;
                }

                $member = CurrentMember::get();

                return $member !== null
                    && (int) $record->member_id === (int) $member->id
                    && $record->status === 'active';
            })
            ->modalHeading(__('Early loan settlement'))
            ->modalDescription(__('Choose to pay the full remaining balance or a partial amount from your cash account.'))
            ->modalWidth('md')
            ->fillForm(function (?Loan $record): array {
                if (! $record instanceof Loan) {
                    return [];
                }

                $context = self::earlySettlementContext($record);

                return [
                    'payment_mode' => $context['cash_covers_full'] ? 'full' : 'partial',
                    'amount' => $context['cash_covers_full']
                        ? $context['required']
                        : max(0.01, round($context['balance'], 2)),
                    'option' => 'roll_up',
                ];
            })
            ->schema(fn (?Loan $record): array => $record instanceof Loan
                ? self::earlySettlementFormSchema($record)
                : [])
            ->action(function (?Loan $record, array $data, Action $action, LoanService $loanService): void {
                if (! $record instanceof Loan) {
                    Notification::make()
                        ->title(__('Settlement failed'))
                        ->body(__('We could not find the loan to settle.'))
                        ->danger()
                        ->send();

                    return;
                }
                $member = CurrentMember::get();

                if ($member === null || (int) $record->member_id !== (int) $member->id) {
                    Notification::make()
                        ->title(__('Settlement failed'))
                        ->body(__('You can only settle your own active loan.'))
                        ->danger()
                        ->send();

                    return;
                }

                $context = self::earlySettlementContext($record);

                if ($context['balance'] < 0.01) {
                    Notification::make()
                        ->title(__('Insufficient cash balance'))
                        ->body(__('Deposit cash to your account before settling this loan.'))
                        ->warning()
                        ->send();

                    return;
                }

                $paymentMode = (string) ($data['payment_mode'] ?? 'partial');
                $amount = $paymentMode === 'full' && $context['cash_covers_full']
                    ? $context['required']
                    : (float) ($data['amount'] ?? 0);

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $loanService->settleLoan(
                            $record,
                            $amount,
                            (string) ($data['option'] ?? 'roll_up'),
                        ),
                        __('Settlement failed'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Early settlement applied'))
                    ->body(__('Your settlement has been recorded. Thank you.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array{
     *     required: float,
     *     balance: float,
     *     cash_covers_full: bool,
     *     required_label: string,
     *     balance_label: string
     * }
     */
    public static function earlySettlementContext(Loan $record): array
    {
        $settlement = app(LoanEarlySettlementService::class);
        $required = $settlement->requiredCash($record);
        $record->loadMissing('member');
        $record->member->unsetRelation('accounts');
        $balance = $record->member->getCashBalance();
        $currency = Setting::get('general', 'currency', 'USD');

        return [
            'required' => $required,
            'balance' => $balance,
            'cash_covers_full' => $balance + 0.00001 >= $required,
            'required_label' => MoneyDisplay::format($required, $currency) ?? number_format($required, 2),
            'balance_label' => MoneyDisplay::format($balance, $currency) ?? number_format($balance, 2),
        ];
    }

    /**
     * @return array<int, Component|\Filament\Schemas\Components\Component>
     */
    public static function earlySettlementFormSchema(Loan $record): array
    {
        $context = self::earlySettlementContext($record);
        $required = $context['required'];
        $balance = $context['balance'];
        $requiredLabel = $context['required_label'];
        $balanceLabel = $context['balance_label'];
        $cashCoversFullPayoff = $context['cash_covers_full'];
        $hasCash = $balance >= 0.01;

        return [
            Placeholder::make('settlement_summary')
                ->label(__('Settlement summary'))
                ->content(new HtmlString(
                    __('Full payoff requires :required. Member cash balance: :balance.', [
                        'required' => '<strong>'.e($requiredLabel).'</strong>',
                        'balance' => '<strong>'.e($balanceLabel).'</strong>',
                    ])
                    .(! $hasCash
                        ? '<p class="mt-2 text-sm text-danger-600 dark:text-danger-400">'
                            .e(__('You need cash in your account before you can settle this loan.'))
                            .'</p>'
                        : ($cashCoversFullPayoff
                            ? ''
                            : '<p class="mt-2 text-sm text-warning-600 dark:text-warning-400">'
                                .e(__('Cash is short of a full payoff. You can still pay a partial amount now or deposit more first.'))
                                .'</p>'))
                )),
            Radio::make('payment_mode')
                ->label(__('How much do you want to pay?'))
                ->options([
                    'full' => __('Pay full payoff (:amount)', ['amount' => $requiredLabel]),
                    'partial' => __('Pay a partial amount'),
                ])
                ->default($cashCoversFullPayoff ? 'full' : 'partial')
                ->disableOptionWhen(fn (string $value): bool => $value === 'full' && ! $cashCoversFullPayoff)
                ->disabled(! $hasCash)
                ->live()
                ->afterStateUpdated(function (?string $state, Set $set) use ($required, $balance, $cashCoversFullPayoff): void {
                    if ($state === 'full' && $cashCoversFullPayoff) {
                        $set('amount', $required);

                        return;
                    }

                    if ($state === 'partial') {
                        $set('amount', max(0.01, round($balance, 2)));
                    }
                }),
            TextInput::make('amount')
                ->label(__('Amount'))
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->maxValue(max(0.01, $balance))
                ->default($cashCoversFullPayoff ? $required : max(0.01, round($balance, 2)))
                ->disabled(fn (Get $get): bool => ! $hasCash || ($get('payment_mode') ?? 'partial') === 'full')
                ->dehydrated()
                ->live()
                ->helperText(
                    fn (Get $get): string => ($get('payment_mode') ?? 'partial') === 'full'
                        ? __('The full payoff amount will be debited from your cash balance.')
                        : __('Enter an amount up to your available cash balance of :balance.', [
                            'balance' => $balanceLabel,
                        ])
                ),
            Select::make('option')
                ->label(__('Schedule option'))
                ->options(Lang::transOptions([
                    'roll_up' => 'Roll remaining into last installment',
                    'skip_future' => 'Skip future installments',
                ]))
                ->default('roll_up')
                ->required()
                ->visible(fn (Get $get): bool => $hasCash
                    && ($get('payment_mode') ?? 'partial') === 'partial'
                    && (float) ($get('amount') ?? 0) < $required - 0.00001)
                ->helperText(__('Choose how to adjust the remaining schedule when paying less than the full balance.')),
        ];
    }
}
