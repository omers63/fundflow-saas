<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\Loans\LoanResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanAdminTransferService;
use App\Services\Loans\LoanDelinquencyService;
use App\Services\Loans\LoanEarlySettlementService;
use App\Services\Loans\LoanGuarantorReplacementService;
use App\Services\Loans\LoanLifecycleService;
use App\Services\Loans\LoanQueueOrderingService;
use App\Services\Loans\LoanRepaymentService;
use App\Services\Loans\LoanSplitExcessFundCashOutService;
use App\Services\Loans\LoanThresholdInstallmentWaiverService;
use App\Services\Loans\LoanTransferPreview;
use App\Services\LoanService;
use App\Support\BusinessDay;
use App\Support\Lang;
use App\Support\LoanSettings;
use Carbon\Carbon;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Forms\Components\Checkbox;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Radio;
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
                    ->options(LoanSettings::graceCycleSelectOptions())
                    ->default(LoanSettings::defaultApplicationGraceCycles())
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

    /** Triage: keep or drop the emergency flag on a pending application before approval. */
    public static function changeToStandard(): Action
    {
        return Action::make('changeToStandard')
            ->label(__('Change to standard'))
            ->icon('heroicon-o-arrow-down-circle')
            ->color('warning')
            ->visible(fn (Loan $record): bool => $record->status === 'pending' && (bool) $record->is_emergency)
            ->requiresConfirmation()
            ->modalHeading(__('Change to standard loan'))
            ->modalDescription(__('The loan will leave the emergency lane and queue in the fund tier matching its amount.'))
            ->action(function (Loan $record): void {
                $record->update(['is_emergency' => false]);

                Notification::make()->title(__('Moved to standard queue'))->success()->send();
            });
    }

    public static function markAsEmergency(): Action
    {
        return Action::make('markAsEmergency')
            ->label(__('Mark as emergency'))
            ->icon('heroicon-o-bolt')
            ->color('danger')
            ->visible(fn (Loan $record): bool => $record->status === 'pending' && ! $record->is_emergency)
            ->requiresConfirmation()
            ->modalHeading(__('Mark as emergency'))
            ->modalDescription(__('The loan will be pushed to the front of intake and use the emergency fund tier.'))
            ->action(function (Loan $record): void {
                $record->update(['is_emergency' => true]);

                Notification::make()->title(__('Marked as emergency'))->success()->send();
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
            ->visible(fn (Loan $record): bool => in_array($record->status, ['approved', 'partially_disbursed'], true))
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
                $suggested = $fundTier
                    ? min($remaining, max(0.0, (float) $fundTier->disbursable_pool))
                    : $policyMax;

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
                        ->default($suggested > 0.01 ? round($suggested, 2) : ($policyMax > 0.01 ? $policyMax : null))
                        ->helperText(__('Suggested tranche is capped by the tier pool that can be paid out now.'))
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

    public static function newDisbursementHeaderAction(): Action
    {
        return TableHeaderIconAction::apply(
            Action::make('newDisbursement')
                ->label(__('New disbursement'))
                ->icon('heroicon-o-plus-circle')
                ->color('primary')
                ->modalHeading(__('New disbursement'))
                ->modalWidth('lg')
                ->schema(function (): array {
                    $loans = Loan::query()
                        ->readyToDisburse()
                        ->with(['member:id,name,member_number'])
                        ->orderByDesc('approved_at')
                        ->get();

                    return [
                        Select::make('loan_id')
                            ->label(__('Loan'))
                            ->options($loans->mapWithKeys(function (Loan $loan): array {
                                $member = $loan->member;
                                $label = ($member?->member_number ?? '—').' — '.($member?->name ?? __('Unknown member'));
                                $remaining = number_format($loan->remainingToDisburse(), 2);

                                return [$loan->id => "{$label} ({$remaining})"];
                            })->all())
                            ->searchable()
                            ->required()
                            ->live(),
                        TextInput::make('amount')
                            ->label(__('Amount to disburse'))
                            ->numeric()
                            ->minValue(0.01)
                            ->required()
                            ->helperText(function (Get $get) use ($loans): ?string {
                                $loan = $loans->firstWhere('id', (int) ($get('loan_id') ?? 0));

                                return $loan
                                    ? __('Remaining: :amount', ['amount' => number_format($loan->remainingToDisburse(), 2)])
                                    : null;
                            }),
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
                        Checkbox::make('force')
                            ->label(__('Force'))
                            ->helperText(__('Override tier pool cap (still limited by master fund and remaining approved).')),
                    ];
                })
                ->action(function (array $data, Action $action, LoanLifecycleService $lifecycle, Component $livewire): void {
                    $loan = Loan::query()->find((int) ($data['loan_id'] ?? 0));

                    if ($loan === null || ! in_array($loan->status, ['approved', 'partially_disbursed'], true)) {
                        Notification::make()
                            ->title(__('Disbursement failed'))
                            ->body(__('Select a loan that is ready to disburse.'))
                            ->danger()
                            ->send();

                        return;
                    }

                    if (
                        ! ActionModalFailure::attemptThrowable(
                            $action,
                            fn () => $lifecycle->disbursePartial(
                                $loan,
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

                    $livewire->resetTable();

                    Notification::make()
                        ->title($loan->fresh()->isFullyDisbursed() ? __('Loan fully disbursed') : __('Partial disbursement recorded'))
                        ->success()
                        ->send();
                }),
        );
    }

    public static function cashOutSplitExcessFund(): Action
    {
        return Action::make('cashOutSplitExcessFund')
            ->label(__('Cash out split excess fund'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('warning')
            ->visible(function (Loan $record): bool {
                return app(LoanSplitExcessFundCashOutService::class)->offersCashOut($record);
            })
            ->modalWidth('lg')
            ->fillForm(function (Loan $record): array {
                $summary = app(LoanSplitExcessFundCashOutService::class)->summary($record);

                return [
                    'amount' => $summary['max_transferable'] > 0.00001 ? $summary['max_transferable'] : null,
                    'cashed_out_at' => BusinessDay::now(),
                ];
            })
            ->schema(function (Loan $record): array {
                $summary = app(LoanSplitExcessFundCashOutService::class)->summary($record);
                $cashOutService = app(LoanSplitExcessFundCashOutService::class);
                $disbursedAt = $cashOutService->disbursementAt($record);

                return [
                    Placeholder::make('split_excess_summary')
                        ->label(__('Split excess fund summary'))
                        ->content(new HtmlString(
                            '<ul class="list-disc space-y-1 ps-4 text-sm">'
                            .'<li>'.e(__('Fund balance at disbursement: :amount', ['amount' => number_format((float) ($summary['fund_balance_at_disbursement'] ?? 0), 2)])).'</li>'
                            .'<li>'.e(__('Member share at disbursement: :amount', ['amount' => number_format((float) $record->member_portion, 2)])).'</li>'
                            .'<li>'.e(__('Excess at disbursement: :amount', ['amount' => number_format($summary['disbursement_excess'], 2)])).'</li>'
                            .'<li>'.e(__('Already moved to cash: :amount', ['amount' => number_format($summary['already_transferred'], 2)])).'</li>'
                            .'<li>'.e(__('Remaining eligible: :amount', ['amount' => number_format($summary['remaining_eligible'], 2)])).'</li>'
                            .'<li>'.e(__('Member fund balance: :amount', ['amount' => number_format($summary['member_fund_balance'], 2)])).'</li>'
                            .($summary['fund_shortfall'] > 0.00001
                                ? '<li class="text-warning-600 dark:text-warning-400">'.e(__('Fund shortfall for full payout: :amount (fund may go negative).', ['amount' => number_format($summary['fund_shortfall'], 2)])).'</li>'
                                : '')
                            .'</ul>'
                        ))
                        ->columnSpanFull(),
                    TextInput::make('amount')
                        ->label(__('Amount to cash out'))
                        ->numeric()
                        ->required()
                        ->minValue(0.01)
                        ->maxValue(max(0.01, $summary['max_transferable']))
                        ->default($summary['max_transferable'] > 0.00001 ? $summary['max_transferable'] : null)
                        ->helperText(__('Transfers fund to member cash (even if the current fund balance is insufficient), then creates an approved cash-out request for bank payout.')),
                    DateTimePicker::make('cashed_out_at')
                        ->label(__('Cash-out date'))
                        ->seconds(false)
                        ->native(false)
                        ->required()
                        ->default(BusinessDay::now())
                        ->minDate($disbursedAt)
                        ->helperText(__('Must be on or after the loan disbursement date.')),
                    Textarea::make('notes')
                        ->label(__('Notes'))
                        ->rows(2)
                        ->maxLength(500),
                ];
            })
            ->action(function (Loan $record, array $data, Action $action, LoanSplitExcessFundCashOutService $cashOutService): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $cashOutService->cashOut(
                            $record,
                            (float) $data['amount'],
                            filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
                            auth('tenant')->id(),
                            isset($data['cashed_out_at']) ? Carbon::parse((string) $data['cashed_out_at']) : BusinessDay::now(),
                        ),
                        __('Cash-out failed'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Split excess fund cash-out recorded'))
                    ->success()
                    ->send();
            })
            ->after(fn (Component $livewire): mixed => LoanResource::dispatchInsightsRefresh($livewire));
    }

    public static function earlySettle(): Action
    {
        return Action::make('earlySettle')
            ->label(__('Early settlement'))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(fn (Loan $record): bool => $record->status === 'active')
            ->fillForm(function (Loan $record): array {
                $settlement = app(LoanEarlySettlementService::class);
                $required = $settlement->requiredCash($record);
                $record->loadMissing('member');
                $record->member->unsetRelation('accounts');
                $balance = $record->member->getCashBalance();

                return [
                    'amount' => $balance + 0.00001 >= $required
                        ? $required
                        : max(0.01, round($balance, 2)),
                    'option' => 'roll_up',
                ];
            })
            ->schema(fn (Loan $record): array => self::earlySettlementFormSchema($record))
            ->action(function (Loan $record, array $data, Action $action, LoanService $service): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $service->settleLoan(
                            $record,
                            (float) $data['amount'],
                            (string) ($data['option'] ?? 'roll_up'),
                        ),
                        __('Settlement failed'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Early settlement applied'))->success()->send();
            });
    }

    /**
     * Early settlement for relation-manager table header actions (no row record).
     */
    public static function earlySettleForOwner(\Closure $resolveLoan): Action
    {
        return Action::make('earlySettle')
            ->label(__('Early settlement'))
            ->icon('heroicon-o-check-badge')
            ->color('success')
            ->visible(function () use ($resolveLoan): bool {
                $record = $resolveLoan();

                return $record->status === 'active';
            })
            ->fillForm(function () use ($resolveLoan): array {
                $record = $resolveLoan();
                $required = app(LoanEarlySettlementService::class)->requiredCash($record);
                $record->loadMissing('member');
                $record->member->unsetRelation('accounts');
                $balance = $record->member->getCashBalance();

                return [
                    'amount' => $balance + 0.00001 >= $required
                        ? $required
                        : max(0.01, round($balance, 2)),
                    'option' => 'roll_up',
                ];
            })
            ->schema(function () use ($resolveLoan): array {
                return self::earlySettlementFormSchema($resolveLoan());
            })
            ->action(function (array $data, Action $action, LoanService $service) use ($resolveLoan): void {
                $record = $resolveLoan();

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $service->settleLoan(
                            $record,
                            (float) $data['amount'],
                            (string) ($data['option'] ?? 'roll_up'),
                        ),
                        __('Settlement failed'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Early settlement applied'))->success()->send();
            });
    }

    public static function waiveThresholdInstallments(): Action
    {
        return Action::make('waiveThresholdInstallments')
            ->label(__('Waive threshold installments'))
            ->icon('heroicon-o-hand-raised')
            ->color('warning')
            ->requiresConfirmation()
            ->modalHeading(__('Waive remaining threshold installments'))
            ->modalDescription(__('Use only for exceptional cases after the master fund portion is fully repaid. Remaining settlement-threshold EMIs will be waived and the loan marked completed without further cash collection.'))
            ->visible(fn (Loan $record): bool => $record->canWaiveRemainingThresholdInstallments())
            ->schema(fn (Loan $record): array => self::thresholdWaiverFormSchema($record))
            ->action(function (Loan $record, array $data, Action $action, LoanService $service): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $service->waiveRemainingThresholdInstallments(
                            $record,
                            (string) ($data['reason'] ?? ''),
                            auth('tenant')->id(),
                        ),
                        __('Threshold waiver failed'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Threshold installments waived'))
                    ->body(__('The loan repayment cycle is now complete.'))
                    ->success()
                    ->send();
            });
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component|\Filament\Schemas\Components\Component>
     */
    public static function thresholdWaiverFormSchema(Loan $record): array
    {
        $waiver = app(LoanThresholdInstallmentWaiverService::class);
        $currency = Setting::get('general', 'currency', 'USD');
        $installments = $waiver->waivableInstallments($record);
        $waiveAmount = round((float) $installments->sum('amount'), 2);
        $masterPortion = (float) $record->master_portion;
        $repaidToMaster = (float) $record->repaid_to_master;
        $threshold = $record->fullRepaymentThreshold();
        $collected = $record->totalPrincipalCollected();

        return [
            Placeholder::make('waiver_summary')
                ->label(__('Summary'))
                ->content(new HtmlString(
                    '<ul class="list-disc space-y-1 ps-4 text-sm text-gray-600 dark:text-gray-300">'
                    .'<li>'.e(__('Master fund repaid: :repaid / :total', [
                        'repaid' => MoneyDisplay::format($repaidToMaster, $currency) ?? '—',
                        'total' => MoneyDisplay::format($masterPortion, $currency) ?? '—',
                    ])).'</li>'
                    .'<li>'.e(__('Principal collected on schedule: :amount', [
                        'amount' => MoneyDisplay::format($collected, $currency) ?? '—',
                    ])).'</li>'
                    .'<li>'.e(__('Full repayment threshold: :amount', [
                        'amount' => MoneyDisplay::format($threshold, $currency) ?? '—',
                    ])).'</li>'
                    .'<li>'.e(trans_choice(
                        ':count installment to waive|:count installments to waive',
                        $installments->count(),
                        ['count' => $installments->count()],
                    )).' · '.e(MoneyDisplay::format($waiveAmount, $currency) ?? '—').'</li>'
                    .'</ul>'
                )),
            Textarea::make('reason')
                ->label(__('Waiver reason'))
                ->required()
                ->rows(4)
                ->maxLength(2000)
                ->placeholder(__('Document the board decision or exceptional circumstance…')),
        ];
    }

    /**
     * @return array<int, \Filament\Forms\Components\Component|\Filament\Schemas\Components\Component>
     */
    public static function earlySettlementFormSchema(Loan $record): array
    {
        $settlement = app(LoanEarlySettlementService::class);
        $required = $settlement->requiredCash($record);
        $record->loadMissing('member');
        $record->member->unsetRelation('accounts');
        $balance = $record->member->getCashBalance();
        $currency = Setting::get('general', 'currency', 'USD');
        $requiredLabel = MoneyDisplay::format($required, $currency) ?? number_format($required, 2);
        $balanceLabel = MoneyDisplay::format($balance, $currency) ?? number_format($balance, 2);
        $cashCoversFullPayoff = $balance + 0.00001 >= $required;

        return [
            Placeholder::make('settlement_summary')
                ->label(__('Settlement summary'))
                ->content(new HtmlString(
                    __('Full payoff requires :required. Member cash balance: :balance.', [
                        'required' => '<strong>'.e($requiredLabel).'</strong>',
                        'balance' => '<strong>'.e($balanceLabel).'</strong>',
                    ])
                    .($cashCoversFullPayoff ? '' : '<p class="mt-2 text-sm text-warning-600 dark:text-warning-400">'
                        .e(__('Cash is short of a full payoff. Enter an amount up to your cash balance, or deposit more first.'))
                        .'</p>')
                )),
            TextInput::make('amount')
                ->label(__('Amount'))
                ->numeric()
                ->required()
                ->minValue(0.01)
                ->default($cashCoversFullPayoff ? $required : max(0.01, $balance))
                ->helperText(
                    $cashCoversFullPayoff
                    ? __('Enter the full payoff amount or a smaller lump sum.')
                    : __('Maximum you can settle now from cash: :balance. Full payoff needs :required.', [
                        'balance' => $balanceLabel,
                        'required' => $requiredLabel,
                    ])
                ),
            Select::make('option')
                ->label(__('Schedule option'))
                ->options(Lang::transOptions([
                    'roll_up' => 'Roll-up (compress) — shorten remaining schedule',
                    'skip_future' => 'Skip cycles — keep schedule length',
                ]))
                ->default('roll_up')
                ->required()
                ->visible(fn (Get $get): bool => (float) ($get('amount') ?? 0) < $required - 0.00001)
                ->helperText(__('Roll-up marks covered EMIs paid and removes the same count from the end of the schedule. Skip marks covered cycles as skipped while keeping the original term.')),
        ];
    }

    public static function transferLoanAdmin(): Action
    {
        return Action::make('transferLoanAdmin')
            ->label(__('Loan Transfer'))
            ->icon('heroicon-o-arrows-right-left')
            ->color('danger')
            ->visible(function (mixed $record): bool {
                $loan = self::resolveLoan($record);

                return $loan !== null
                    && $loan->status === 'active'
                    && $loan->isFullyDisbursed()
                    && $loan->admin_transferred_at === null;
            })
            ->modalHeading(__('Loan Transfer'))
            ->modalDescription(__('Reassigns this loan to another member. Remaining keeps the same loan row; Full unwinds fund/loan exposure (no cash clawback) then redisburses a new loan.'))
            ->modalWidth('xl')
            ->fillForm(function (mixed $record): array {
                $loan = self::resolveLoan($record);

                if ($loan === null) {
                    return [];
                }

                $hasGuarantor = filled($loan->guarantor_member_id)
                    && (int) $loan->guarantor_member_id !== (int) $loan->member_id;

                return [
                    'mode' => LoanTransferPreview::MODE_REMAINING,
                    'recipient_scope' => $hasGuarantor ? 'guarantor' : 'any_member',
                    'recipient_member_id' => $hasGuarantor ? $loan->guarantor_member_id : null,
                    'allow_second_running_loan' => false,
                    'fund_entirely_from_master' => false,
                    'suspend_borrower' => true,
                ];
            })
            ->schema(function (mixed $record): array {
                $loan = self::resolveLoan($record);

                if ($loan === null) {
                    return [];
                }

                return [
                    Select::make('mode')
                        ->label(__('Transfer mode'))
                        ->options(Lang::transOptions([
                            LoanTransferPreview::MODE_REMAINING => 'Remaining obligation',
                            LoanTransferPreview::MODE_FULL => 'Full unwind and redisburse',
                        ]))
                        ->required()
                        ->live(),
                    Radio::make('recipient_scope')
                        ->label(__('Recipient'))
                        ->options(function () use ($loan): array {
                            $options = [
                                'any_member' => __('Any active member'),
                            ];

                            if (
                                filled($loan->guarantor_member_id)
                                && (int) $loan->guarantor_member_id !== (int) $loan->member_id
                            ) {
                                $options = [
                                    'guarantor' => __('Assigned guarantor'),
                                    ...$options,
                                ];
                            }

                            return $options;
                        })
                        ->required()
                        ->live()
                        ->afterStateUpdated(function ($state, callable $set) use ($loan): void {
                            if ($state === 'guarantor') {
                                $set('recipient_member_id', $loan->guarantor_member_id);
                            }
                        }),
                    Placeholder::make('guarantor_recipient')
                        ->label(__('Guarantor'))
                        ->content(fn (): string => $loan->guarantor?->name
                            ?? __('No guarantor assigned'))
                        ->visible(fn (Get $get): bool => $get('recipient_scope') === 'guarantor'),
                    MemberSelect::make('recipient_member_id')
                        ->label(__('Recipient member'))
                        ->required(fn (Get $get): bool => $get('recipient_scope') === 'any_member')
                        ->visible(fn (Get $get): bool => $get('recipient_scope') === 'any_member')
                        ->live()
                        ->disableOptionWhen(fn (mixed $value): bool => (int) $value === (int) $loan->member_id),
                    Toggle::make('allow_second_running_loan')
                        ->label(__('Allow second running loan'))
                        ->helperText(__('Required when the recipient is already at the maximum active loans cap.'))
                        ->live(),
                    Toggle::make('fund_entirely_from_master')
                        ->label(__('Fund entirely from master'))
                        ->helperText(__('Sets member portion to zero and funds the redisbursement 100% from master fund.'))
                        ->visible(fn (Get $get): bool => $get('mode') === LoanTransferPreview::MODE_FULL)
                        ->live(),
                    Toggle::make('suspend_borrower')
                        ->label(__('Suspend original borrower'))
                        ->default(true)
                        ->helperText(__('Same default as Guarantor Transfer.')),
                    Placeholder::make('transfer_preview')
                        ->label(__('Preview'))
                        ->content(function (Get $get) use ($loan): HtmlString {
                            return self::adminTransferPreviewHtml($loan, $get);
                        })
                        ->columnSpanFull(),
                ];
            })
            ->action(function (mixed $record, array $data, Action $action, LoanAdminTransferService $transfers): void {
                $loan = self::resolveLoan($record);

                if ($loan === null) {
                    ActionModalFailure::present($action, __('Select a valid loan.'), __('Cannot transfer loan'));

                    return;
                }

                $scope = (string) ($data['recipient_scope'] ?? 'guarantor');
                $recipientId = $scope === 'guarantor'
                    ? (int) $loan->guarantor_member_id
                    : (int) ($data['recipient_member_id'] ?? 0);

                $recipient = Member::query()->find($recipientId);

                if ($recipient === null) {
                    ActionModalFailure::present($action, __('Select a valid recipient member.'), __('Cannot transfer loan'));

                    return;
                }

                $mode = (string) ($data['mode'] ?? LoanTransferPreview::MODE_REMAINING);

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $transfers->transfer(
                            $loan,
                            $recipient,
                            $mode,
                            (bool) ($data['allow_second_running_loan'] ?? false),
                            (bool) ($data['fund_entirely_from_master'] ?? false),
                            (bool) ($data['suspend_borrower'] ?? true),
                        ),
                        __('Cannot transfer loan'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Loan transferred'))
                    ->success()
                    ->send();
            });
    }

    private static function resolveLoan(mixed $record): ?Loan
    {
        if ($record instanceof Loan) {
            return $record;
        }

        if ($record instanceof LoanInstallment) {
            return $record->loan;
        }

        return null;
    }

    private static function adminTransferPreviewHtml(Loan $record, Get $get): HtmlString
    {
        $mode = (string) ($get('mode') ?: LoanTransferPreview::MODE_REMAINING);
        $scope = (string) ($get('recipient_scope') ?: 'guarantor');
        $recipientId = $scope === 'guarantor'
            ? (int) $record->guarantor_member_id
            : (int) ($get('recipient_member_id') ?: 0);
        $recipient = $recipientId > 0
            ? Member::query()->with('accounts')->find($recipientId)
            : null;

        if ($recipient === null) {
            return new HtmlString('<p class="text-sm text-gray-500">'.e(__('Select a recipient to preview amounts.')).'</p>');
        }

        try {
            $preview = app(LoanAdminTransferService::class)->preview(
                $record,
                $recipient,
                $mode,
                (bool) $get('fund_entirely_from_master'),
            );
        } catch (\Throwable $e) {
            return new HtmlString('<p class="text-sm text-danger-600">'.e($e->getMessage()).'</p>');
        }

        $lines = [
            __('Recipient: :name', ['name' => $recipient->name]),
            __('Approved amount: :amount', ['amount' => number_format($preview->approvedAmount, 2)]),
        ];

        if ($mode === LoanTransferPreview::MODE_REMAINING) {
            $lines[] = __('Remaining obligation (master unpaid): :amount', [
                'amount' => number_format($preview->remainingObligation, 2),
            ]);
            $lines[] = __('No fund or cash unwind — same loan row is reassigned.');
        } else {
            $lines[] = __('Loan account balance to clear: :amount', [
                'amount' => number_format($preview->loanAccountBalance, 2),
            ]);
            $lines[] = __('Borrower fund restore: :amount', [
                'amount' => number_format($preview->fundRestoreAmount, 2),
            ]);
            $lines[] = __('New member portion: :amount', [
                'amount' => number_format($preview->redisbursePortions['member_portion'], 2),
            ]);
            $lines[] = __('New master portion: :amount', [
                'amount' => number_format($preview->redisbursePortions['master_portion'], 2),
            ]);
            $lines[] = __('Full mode does not claw back cash already paid to the borrower. Redisbursement credits the recipient cash account.');

            if (! $preview->recipientFundSufficient) {
                $lines[] = __('Recipient fund (:balance) is below required (:required). Enable “Fund entirely from master” or top up.', [
                    'balance' => number_format($preview->recipientFundBalance, 2),
                    'required' => number_format($preview->requiredRecipientFund, 2),
                ]);
            }

            if (! $preview->masterFundSufficientForRedisburse) {
                $lines[] = __('Master fund (:balance) is below redisburse master portion (:portion).', [
                    'balance' => number_format($preview->masterFundBalance, 2),
                    'portion' => number_format($preview->redisbursePortions['master_portion'], 2),
                ]);
            }
        }

        if ($preview->recipientAtActiveLoanCap) {
            $lines[] = __('Recipient is at the active loan cap (:count / :max). Enable “Allow second running loan”.', [
                'count' => $preview->recipientActiveLoanCount,
                'max' => $preview->maxActiveLoans,
            ]);
        }

        $html = '<ul class="list-disc space-y-1 ps-4 text-sm">'
            .collect($lines)->map(fn (string $line): string => '<li>'.e($line).'</li>')->implode('')
            .'</ul>';

        return new HtmlString($html);
    }

    public static function reinstateSuspendedBorrower(): Action
    {
        return Action::make('reinstateSuspendedBorrower')
            ->label(__('Reinstate borrower'))
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
    public static function queueTableActions(string $tab = 'intake'): array
    {
        $actions = match ($tab) {
            'process' => [
                self::disburse(),
                self::reviewLink(),
            ],
            'completed' => [
                self::reviewLink(),
            ],
            default => [
                self::approve(),
                self::reject(),
                self::changeToStandard(),
                self::markAsEmergency(),
                self::reviewLink(),
            ],
        };

        return array_map(
            fn (Action $action): Action => self::withInsightsRefresh($action),
            $actions,
        );
    }

    private static function reviewLink(): Action
    {
        return Action::make('review')
            ->label(fn (Loan $record): string => $record->status === 'pending'
                ? __('Review application')
                : __('View loan'))
            ->icon('heroicon-o-eye')
            ->color('gray')
            ->url(fn (Loan $record): string => $record->status === 'pending'
                ? LoanResource::getUrl('edit', ['record' => $record])
                : LoanResource::getUrl('view', ['record' => $record]));
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
                self::cashOutSplitExcessFund(),
                self::earlySettle(),
                self::waiveThresholdInstallments(),
                self::applyOpenRepayment(),
                self::reinstateSuspendedBorrower(),
                self::assignGuarantorReplacement(),
                self::cancel(),
            ],
        );
    }

    public static function assignGuarantorReplacement(): Action
    {
        return Action::make('assignGuarantorReplacement')
            ->label(__('Match guarantor nomination'))
            ->icon('heroicon-o-user-plus')
            ->color('warning')
            ->visible(function (Loan $record): bool {
                $open = app(LoanGuarantorReplacementService::class)->latestOpenRequestForLoan($record);

                return $open?->isPendingAdmin() ?? false;
            })
            ->modalHeading(__('Match guarantor nomination'))
            ->modalDescription(function (Loan $record): string {
                $open = app(LoanGuarantorReplacementService::class)->latestOpenRequestForLoan($record);

                return __('Borrower nominated “:name”. Select the matching member. They must then accept.', [
                    'name' => $open?->proposed_guarantor_name ?? __('(unnamed)'),
                ]).($open?->note
                    ? ' '.__('Note: :note', ['note' => $open->note])
                    : '');
            })
            ->schema([
                MemberSelect::make('proposed_guarantor_member_id')
                    ->label(__('Matched member'))
                    ->required(),
            ])
            ->action(function (Loan $record, array $data, Action $action): void {
                $open = app(LoanGuarantorReplacementService::class)->latestOpenRequestForLoan($record);

                if ($open === null || ! $open->isPendingAdmin()) {
                    Notification::make()
                        ->title(__('No pending nomination'))
                        ->warning()
                        ->send();

                    return;
                }

                $proposed = Member::query()->findOrFail((int) $data['proposed_guarantor_member_id']);

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => app(LoanGuarantorReplacementService::class)->assignProposedGuarantor(
                            $open,
                            $proposed,
                            auth('tenant')->user(),
                        ),
                        __('Could not match guarantor'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Guarantor matched'))
                    ->body(__('The proposed guarantor has been notified to accept.'))
                    ->success()
                    ->send();
            });
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
