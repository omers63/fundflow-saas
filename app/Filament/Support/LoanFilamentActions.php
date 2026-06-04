<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\Loans\LoanEarlySettlementService;
use App\Services\Loans\LoanLifecycleService;
use App\Services\Loans\LoanQueueOrderingService;
use App\Services\Loans\LoanRepaymentService;
use App\Services\LoanService;
use App\Support\BusinessDay;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Support\HtmlString;
use Livewire\Component;

final class LoanFilamentActions
{
    public static function approve(): Action
    {
        return Action::make('approve')
            ->label(__('Approve'))
            ->icon('heroicon-o-check-circle')
            ->color('success')
            ->visible(fn (Loan $record): bool => $record->status === 'pending')
            ->fillForm(fn (Loan $record): array => [
                'amount_approved' => $record->amount_requested,
                'is_emergency' => $record->is_emergency,
                'has_grace_cycle' => $record->has_grace_cycle ?? true,
                'grace_cycles' => $record->grace_cycles ?? ($record->has_grace_cycle ? 1 : 0),
                'approved_at' => BusinessDay::now(),
            ])
            ->schema(fn (Loan $record): array => [
                TextInput::make('amount_approved')
                    ->label(__('Approved amount'))
                    ->numeric()
                    ->required()
                    ->live(),
                Toggle::make('is_emergency')
                    ->label(__('Emergency loan'))
                    ->live()
                    ->helperText(__('Bypasses standard queue; uses emergency fund tier.')),
                Select::make('grace_cycles')
                    ->label(__('Grace cycles before first repayment'))
                    ->options([
                        0 => __('None'),
                        1 => __('One cycle'),
                        2 => __('Two cycles'),
                    ])
                    ->default(1)
                    ->required()
                    ->live()
                    ->afterStateUpdated(fn ($state, callable $set) => $set('has_grace_cycle', ((int) $state) > 0)),
                Toggle::make('has_grace_cycle')
                    ->label(__('Grace enabled'))
                    ->dehydrated()
                    ->default(true)
                    ->hidden(),
                DateTimePicker::make('approved_at')
                    ->label(__('Approval date'))
                    ->seconds(false)
                    ->native(false)
                    ->required(),
                Placeholder::make('repayment_preview')
                    ->label(__('Schedule preview'))
                    ->content(function (Get $get) use ($record): HtmlString {
                        $amount = (float) ($get('amount_approved') ?? $record->amount_requested);

                        return LoanApprovalPreview::html($record, $amount, (bool) ($get('is_emergency') ?? false));
                    })
                    ->columnSpanFull(),
            ])
            ->action(function (Loan $record, array $data, Action $action, LoanLifecycleService $lifecycle): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        function () use ($record, $data, $lifecycle): void {
                            $graceCycles = (int) ($data['grace_cycles'] ?? 1);
                            $lifecycle->approveLoan(
                                $record,
                                (float) $data['amount_approved'],
                                (bool) ($data['is_emergency'] ?? false),
                                $graceCycles > 0,
                                $graceCycles,
                                isset($data['approved_at']) ? Carbon::parse((string) $data['approved_at']) : BusinessDay::now(),
                            );
                        },
                        __('Cannot approve'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Loan approved'))->success()->send();
            });
    }

    public static function reject(): Action
    {
        return Action::make('reject')
            ->label(__('Reject'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Loan $record): bool => $record->status === 'pending')
            ->schema([
                Textarea::make('rejection_reason')
                    ->label(__('Rejection reason'))
                    ->required()
                    ->rows(3)
                    ->maxLength(1000),
            ])
            ->action(function (Loan $record, array $data, Action $action, LoanLifecycleService $lifecycle): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $lifecycle->rejectLoan($record, (string) $data['rejection_reason']),
                        __('Cannot reject'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Loan rejected'))->success()->send();
            });
    }

    public static function cancel(): Action
    {
        return Action::make('cancel')
            ->label(__('Cancel'))
            ->icon('heroicon-o-no-symbol')
            ->color('gray')
            ->visible(fn (Loan $record): bool => $record->status === 'pending')
            ->requiresConfirmation()
            ->action(function (Loan $record, Action $action, LoanLifecycleService $lifecycle): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $lifecycle->cancelLoan($record),
                        __('Cannot cancel'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Application cancelled'))->success()->send();
            });
    }

    public static function disburse(): Action
    {
        return Action::make('disburse')
            ->label(fn (Loan $record): string => $record->isFullyDisbursed() ? __('Fully disbursed') : __('Disburse'))
            ->icon('heroicon-o-banknotes')
            ->color('primary')
            ->visible(fn (Loan $record): bool => $record->status === 'approved')
            ->disabled(fn (Loan $record): bool => $record->remainingToDisburse() <= 0.01)
            ->modalWidth('lg')
            ->schema(function (Loan $record): array {
                $record->loadMissing(['fundTier', 'member', 'loanTier']);
                $remaining = $record->remainingToDisburse();
                $masterBal = (float) (Account::masterFund()?->balance ?? 0);
                $fundTier = $record->fundTier;
                $declaredPool = $fundTier ? max(0.0, (float) $fundTier->allocated_amount) : $remaining;
                $policyMax = min($remaining, $declaredPool);
                $masterMax = min($remaining, $masterBal);

                return [
                    Placeholder::make('info')
                        ->label('')
                        ->content(new HtmlString(
                            '<p class="text-sm">'.e(__('Remaining: :amount', ['amount' => number_format($remaining, 2)])).'</p>'
                            .'<p class="text-sm">'.e(__('Tier pool cap: :amount', ['amount' => number_format($declaredPool, 2)])).'</p>'
                            .'<p class="text-sm">'.e(__('Master fund: :amount', ['amount' => number_format($masterBal, 2)])).'</p>'
                        ))
                        ->columnSpanFull(),
                    Checkbox::make('force')
                        ->label(__('Force'))
                        ->live()
                        ->visible($fundTier !== null && $declaredPool + 0.01 < $remaining)
                        ->helperText(__('Override tier pool cap (still limited by master fund and remaining approved).')),
                    TextInput::make('amount')
                        ->label(__('Amount to disburse'))
                        ->numeric()
                        ->minValue(0.01)
                        ->maxValue(fn (Get $get): float => $get('force') ? $masterMax : max(0.01, $policyMax))
                        ->default($policyMax > 0.01 ? $policyMax : null)
                        ->required(),
                    Textarea::make('notes')
                        ->label(__('Notes'))
                        ->rows(2)
                        ->maxLength(500),
                    DateTimePicker::make('disbursed_at')
                        ->label(__('Disbursement date'))
                        ->seconds(false)
                        ->native(false)
                        ->default(BusinessDay::now())
                        ->required(),
                ];
            })
            ->action(function (Loan $record, array $data, Action $action, LoanLifecycleService $lifecycle): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $lifecycle->disbursePartial(
                            $record,
                            (float) $data['amount'],
                            filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
                            isset($data['disbursed_at']) ? Carbon::parse((string) $data['disbursed_at']) : BusinessDay::now(),
                            (bool) ($data['force'] ?? false),
                        ),
                        __('Disbursement failed'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title($record->fresh()->isFullyDisbursed() ? __('Loan fully disbursed') : __('Partial disbursement recorded'))
                    ->success()
                    ->send();
            });
    }

    public static function markBankPayout(): Action
    {
        return Action::make('markBankPayout')
            ->label(__('Mark bank payout'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('warning')
            ->visible(fn (Loan $record): bool => $record->status === 'active' && $record->payout_at === null)
            ->requiresConfirmation()
            ->modalDescription(__('Confirm the loan proceeds were sent to the member bank account.'))
            ->schema([
                DateTimePicker::make('payout_at')
                    ->label(__('Payout date'))
                    ->seconds(false)
                    ->native(false)
                    ->default(BusinessDay::now())
                    ->required(),
            ])
            ->action(function (Loan $record, array $data, Action $action, LoanLifecycleService $lifecycle): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $lifecycle->markBankPayout(
                            $record,
                            isset($data['payout_at']) ? Carbon::parse((string) $data['payout_at']) : BusinessDay::now(),
                        ),
                        __('Could not record payout'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Bank payout recorded'))->success()->send();
            });
    }

    public static function partialEarlySettle(): Action
    {
        return Action::make('partialEarlySettle')
            ->label(__('Partial early settlement'))
            ->icon('heroicon-o-banknotes')
            ->color('info')
            ->visible(fn (Loan $record): bool => $record->status === 'active')
            ->schema([
                TextInput::make('amount')
                    ->label(__('Amount'))
                    ->numeric()
                    ->required()
                    ->minValue(0.01),
                Select::make('option')
                    ->label(__('Schedule option'))
                    ->options([
                        'roll_up' => __('Roll remaining into last installment'),
                        'skip_future' => __('Skip future installments'),
                    ])
                    ->default('roll_up')
                    ->required(),
            ])
            ->action(function (Loan $record, array $data, Action $action, LoanEarlySettlementService $service): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $service->partialEarlySettle(
                            $record,
                            (float) $data['amount'],
                            (string) ($data['option'] ?? 'roll_up'),
                        ),
                        __('Partial settlement failed'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Partial settlement applied'))->success()->send();
            });
    }

    public static function earlySettle(): Action
    {
        return Action::make('earlySettle')
            ->label(__('Early settle'))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (Loan $record): bool => $record->status === 'active')
            ->requiresConfirmation()
            ->modalDescription(fn (Loan $record): string => __('Pay all remaining installments from member cash. Required: :amount', [
                'amount' => number_format(app(LoanEarlySettlementService::class)->requiredCash($record), 2),
            ]))
            ->action(function (Loan $record, Action $action, LoanService $service): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $service->earlySettle($record),
                        __('Settlement failed'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Loan early settled'))->success()->send();
            });
    }

    public static function transferGuarantorLiability(): Action
    {
        return Action::make('transferGuarantorLiability')
            ->label(__('Transfer liability to guarantor'))
            ->icon('heroicon-o-shield-exclamation')
            ->color('warning')
            ->visible(fn (Loan $record): bool => $record->status === 'active'
                && $record->guarantor_member_id
                && $record->guarantor_liability_transferred_at === null
                && ! $record->isGuarantorReleased())
            ->requiresConfirmation()
            ->modalHeading(__('Transfer liability to guarantor'))
            ->modalDescription(__('Future overdue installments will be collected from the guarantor fund immediately instead of following the borrower warning cycle.'))
            ->action(function (Loan $record, Action $action, LoanDelinquencyService $delinquency): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $delinquency->transferGuarantorLiability($record),
                        __('Cannot transfer liability'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Guarantor liability active'))
                    ->success()
                    ->send();
            });
    }

    public static function reinstateSuspendedBorrower(): Action
    {
        return Action::make('reinstateSuspendedBorrower')
            ->label(__('Reinstate suspended borrower'))
            ->icon('heroicon-o-user-plus')
            ->color('success')
            ->visible(fn (Loan $record): bool => $record->transferred_to_guarantor_at !== null
                && $record->original_borrower_member_id !== null)
            ->requiresConfirmation()
            ->action(function (Loan $record, Action $action, LoanDelinquencyService $delinquency): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $delinquency->reinstateSuspendedBorrower($record),
                        __('Cannot reinstate'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Borrower reinstated'))->success()->send();
            });
    }

    public static function restoreBorrowerLiability(): Action
    {
        return Action::make('restoreBorrowerLiability')
            ->label(__('Restore borrower liability'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('gray')
            ->visible(fn (Loan $record): bool => $record->guarantor_liability_transferred_at !== null)
            ->requiresConfirmation()
            ->modalDescription(__('Returns default handling to the standard borrower warning cycle.'))
            ->action(function (Loan $record, Action $action, LoanDelinquencyService $delinquency): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $delinquency->restoreBorrowerLiability($record),
                        __('Cannot restore liability'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Borrower liability restored'))
                    ->success()
                    ->send();
            });
    }

    public static function applyOpenRepayment(): Action
    {
        return Action::make('applyOpenRepayment')
            ->label(__('Apply open-period repayment'))
            ->icon('heroicon-o-currency-dollar')
            ->color('primary')
            ->visible(fn (Loan $record): bool => $record->status === 'active'
                && app(LoanRepaymentService::class)->shouldOfferOpenPeriodRepayment($record->member))
            ->requiresConfirmation()
            ->modalDescription(fn (Loan $record): string => app(LoanRepaymentService::class)
                ->openPeriodRepaymentModalDescription($record->member))
            ->action(function (Loan $record, LoanRepaymentService $repayments): void {
                $member = $record->member;
                $result = $repayments->applyOpenPeriodRepaymentForMember($member);

                $notification = Notification::make()
                    ->title(match ($result) {
                        'applied' => __('Repayment applied'),
                        'insufficient' => __('Insufficient cash'),
                        default => __('Nothing to apply'),
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

    /**
     * @return array<int, Action>
     */
    public static function workflowActions(): array
    {
        return array_map(
            fn (Action $action): Action => self::withInsightsRefresh($action),
            [
                self::approve(),
                self::reject(),
                self::disburse(),
                self::markBankPayout(),
                self::partialEarlySettle(),
                self::earlySettle(),
                self::applyOpenRepayment(),
                self::transferGuarantorLiability(),
                self::restoreBorrowerLiability(),
                self::reinstateSuspendedBorrower(),
                self::cancel(),
            ],
        );
    }

    private static function withInsightsRefresh(Action $action): Action
    {
        return $action->after(fn (Component $livewire): mixed => LoanResource::dispatchInsightsRefresh($livewire));
    }

    /**
     * @return array<int, BulkAction>
     */
    public static function bulkActions(): array
    {
        return [
            BulkAction::make('resequenceQueue')
                ->label(__('Resequence queue'))
                ->icon('heroicon-o-arrows-up-down')
                ->requiresConfirmation()
                ->action(function (): void {
                    foreach (FundTier::query()->where('is_active', true)->pluck('id') as $tierId) {
                        LoanQueueOrderingService::resequenceFundTier((int) $tierId);
                    }
                    Notification::make()->title(__('Queue resequenced'))->success()->send();
                })
                ->after(fn (Component $livewire): mixed => LoanResource::dispatchInsightsRefresh($livewire)),
        ];
    }
}
