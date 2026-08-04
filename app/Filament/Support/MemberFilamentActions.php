<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\CashOutRequests\Schemas\CashOutRequestForm;
use App\Filament\Tenant\Resources\FundOutRequests\Schemas\FundOutRequestForm;
use App\Filament\Tenant\Resources\Members\MemberResource;
use App\Filament\Tenant\Resources\MembershipApplications\MembershipApplicationResource;
use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Services\AccountingService;
use App\Services\ContributionCycleService;
use App\Services\Loans\LoanEligibilityOverrideService;
use App\Services\Loans\LoanEligibilityService;
use App\Services\Loans\LoanEmiCollectionCatalogService;
use App\Services\MemberAnnualSubscriptionFeeService;
use App\Services\MemberCashOutService;
use App\Services\MemberFreezeService;
use App\Services\MemberFundOutService;
use App\Services\MemberStatusService;
use App\Services\MemberWithdrawalSettlementService;
use App\Services\Tenant\DirectMessagingService;
use App\Services\Tenant\MemberPortalNotificationService;
use App\Support\BusinessDay;
use App\Support\ContributionPolicySettings;
use App\Support\LoanEligibilityGate;
use App\Support\MemberMembershipPolicy;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Notifications\Notification;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Component;

final class MemberFilamentActions
{
    /**
     * Member list rows open the edit workspace on click — operational actions live on the edit page header.
     *
     * @return list<Action|ActionGroup>
     */
    public static function forMemberListRow(): array
    {
        return [];
    }

    /**
     * Grouped header actions on the member view workspace.
     *
     * @return list<ActionGroup>
     */
    public static function forMemberEditHeader(?Action $treasuryLeadingAction = null): array
    {
        return [
            self::treasuryActionGroup($treasuryLeadingAction),
            self::communicationsActionGroup(),
            self::membershipStatusActionGroup(),
            self::delinquencyAndAdminActionGroup(),
        ];
    }

    /**
     * Row actions for dependents listed on a household head's member edit page.
     *
     * @return list<Action|ActionGroup>
     */
    public static function forHouseholdDependentMemberRow(): array
    {
        return [
            self::treasuryActionGroup(),
            self::communicationsActionGroup(),
            ActionGroup::make([
                self::freeze(),
                self::unfreeze(),
                self::restoreSuspended(),
                self::withdraw(),
                self::reinstate(),
                self::releasePayoutReview(),
            ])
                ->label(__('More'))
                ->icon('heroicon-o-ellipsis-horizontal')
                ->color('gray'),
        ];
    }

    /**
     * @return list<BulkActionGroup>
     */
    public static function forMemberListBulkGroups(): array
    {
        return [
            BulkActionGroup::make([
                self::contributeBulk(),
                self::repaymentBulk(),
                self::adjustCashBulk(),
                self::adjustFundBulk(),
            ])->label(__('Treasury')),
            BulkActionGroup::make([
                self::sendMessageBulk(),
                self::sendNotificationBulk(),
            ])->label(__('Communicate')),
            BulkActionGroup::make([
                self::freezeBulk(),
                self::restoreSuspendedBulk(),
                self::withdrawBulk(),
            ])->label(__('Status')),
            BulkActionGroup::make([
                self::chargeAnnualSubscriptionBulk(),
                self::adminOverrideBulk(),
                self::deleteBulk(),
            ])->label(__('Admin')),
        ];
    }

    /**
     * Flat bulk actions for nested toolbars (e.g. household dependents).
     *
     * @return list<BulkAction>
     */
    public static function forMemberListBulk(): array
    {
        return [
            self::contributeBulk(),
            self::repaymentBulk(),
            self::adjustCashBulk(),
            self::adjustFundBulk(),
            self::sendMessageBulk(),
            self::sendNotificationBulk(),
            self::freezeBulk(),
            self::restoreSuspendedBulk(),
            self::withdrawBulk(),
            self::chargeAnnualSubscriptionBulk(),
            self::adminOverrideBulk(),
            self::deleteBulk(),
        ];
    }

    public static function treasuryActionGroup(?Action $leadingAction = null): ActionGroup
    {
        $actions = [
            self::contribute(),
            self::repayment(),
            self::adjustCash(),
            self::adjustFund(),
            self::cashOut(),
            self::fundOut(),
            self::cashOutFund(),
        ];

        if ($leadingAction !== null) {
            array_unshift($actions, $leadingAction);
        }

        return ActionGroup::make($actions)
            ->label(__('Treasury'))
            ->icon('heroicon-o-banknotes')
            ->color('success');
    }

    public static function communicationsActionGroup(): ActionGroup
    {
        return ActionGroup::make([
            self::sendMessage(),
            self::sendNotification(),
        ])
            ->label(__('Communicate'))
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('gray');
    }

    public static function membershipStatusActionGroup(): ActionGroup
    {
        return ActionGroup::make([
            self::application(),
            self::freeze(),
            self::unfreeze(),
            self::restoreSuspended(),
            self::withdraw(),
            self::reinstate(),
            self::releasePayoutReview(),
        ])
            ->label(__('Membership'))
            ->icon('heroicon-o-user-circle')
            ->color('warning');
    }

    public static function delinquencyAndAdminActionGroup(): ActionGroup
    {
        return ActionGroup::make([
            ...MemberDelinquencyActions::forMemberEditHeaderNested(),
            self::chargeAnnualSubscription(),
            self::adminOverride(),
            self::delete(),
        ])
            ->label(__('Compliance'))
            ->icon('heroicon-o-shield-check')
            ->color('danger');
    }

    public static function contribute(): Action
    {
        return Action::make('contributeMember')
            ->label(__('Contribute'))
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(fn (Member $record): bool => app(MemberMembershipPolicy::class)->canAdminContribute($record))
            ->schema([
                Select::make('cycle')
                    ->label(__('Cycle'))
                    ->options(fn (Member $record): array => app(ContributionCycleService::class)
                        ->contributionCycleSelectOptionsForMember($record))
                    ->required(),
                ContributionCycleHeaderActions::collectOldestArrearsFirstToggle(),
            ])
            ->fillForm(fn (Member $record): array => [
                'cycle' => app(ContributionCycleService::class)
                    ->defaultContributionCycleKeyForMember($record),
                'collect_oldest_arrears_first' => true,
            ])
            ->action(function (Member $record, array $data, Component $livewire): void {
                $cycles = app(ContributionCycleService::class);
                [$month, $year] = $cycles->parseContributionCycleKey($data['cycle']);
                $outcome = $cycles->applyContributionForMemberForPeriod(
                    $record,
                    $month,
                    $year,
                    (bool) ($data['collect_oldest_arrears_first'] ?? true),
                );

                ContributionTableActions::notifyApplyOutcome($outcome, $record->name);

                if (in_array($outcome, ['applied', 'partial'], true)) {
                    self::refreshMembersList($livewire);
                }
            });
    }

    public static function repayment(): Action
    {
        return Action::make('memberLoanRepayment')
            ->label(__('Repayment'))
            ->icon('heroicon-o-currency-dollar')
            ->color('primary')
            ->visible(fn (Member $record): bool => self::isTenantAdmin()
                && $record->hasActiveLoanRepaymentObligation())
            ->schema([
                Select::make('cycle')
                    ->label(__('Cycle'))
                    ->options(fn (): array => app(ContributionCycleService::class)
                        ->contributionCycleSelectOptionsForBulk())
                    ->required(),
                ContributionCycleHeaderActions::collectOldestArrearsFirstToggle(),
            ])
            ->fillForm(fn (): array => [
                ...ContributionCycleHeaderActions::defaultPeriod(),
                'collect_oldest_arrears_first' => true,
            ])
            ->action(function (Member $record, array $data, Component $livewire): void {
                $cycles = app(ContributionCycleService::class);
                [$month, $year] = $cycles->parseContributionCycleKey($data['cycle']);
                $outcome = app(LoanEmiCollectionCatalogService::class)->applyForMember(
                    $record,
                    $month,
                    $year,
                    (bool) ($data['collect_oldest_arrears_first'] ?? true),
                );

                LoanEmiCollectionTableActions::notifyApplyOutcome($outcome, $record->name);

                if (in_array($outcome, ['collected', 'partial'], true)) {
                    self::refreshMembersList($livewire);
                }
            });
    }

    public static function application(): Action
    {
        return Action::make('memberApplication')
            ->label(__('Application'))
            ->icon('heroicon-o-document-plus')
            ->color('info')
            ->visible(fn (Member $record): bool => self::isTenantAdmin()
                && MembershipApplicationResource::canCreate())
            ->url(fn (Member $record): string => self::membershipApplicationCreateUrl($record));
    }

    public static function delete(): Action
    {
        return DeleteAction::make()
            ->after(fn (Component $livewire): mixed => self::refreshMembersList($livewire));
    }

    public static function adjustCash(): Action
    {
        return self::memberAccountAdjustment(
            name: 'adjustMemberCash',
            label: __('Adjust cash'),
            icon: 'heroicon-o-banknotes',
            resolveAccount: fn (Member $record) => self::resolveMemberAccount($record, 'cash'),
            modalDescription: __('Post a manual credit or debit to this member\'s cash account only. Use a clear description for the audit trail.'),
        );
    }

    public static function adjustFund(): Action
    {
        return self::memberAccountAdjustment(
            name: 'adjustMemberFund',
            label: __('Adjust fund'),
            icon: 'heroicon-o-building-library',
            resolveAccount: fn (Member $record) => self::resolveMemberAccount($record, 'fund'),
            modalDescription: __('Post a manual credit or debit to this member\'s fund account only. The balance may go negative for adjustments such as loan allocation. Use a clear description for the audit trail.'),
        );
    }

    public static function cashOut(): Action
    {
        return Action::make('cashOutMember')
            ->label(__('Cash out'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('warning')
            ->visible(fn (Member $record): bool => self::isTenantAdmin()
                && app(MemberMembershipPolicy::class)->canRequestCashOut($record))
            ->modalHeading(__('New cash out'))
            ->modalDescription(__('Creates and approves a cash-out on the selected date. Member and master cash are debited, and an uncleared bank line is added for remittance.'))
            ->modalWidth('2xl')
            ->fillForm(fn (Member $record): array => [
                'member_id' => $record->id,
                'cash_out_date' => self::businessDayPickerDefault(),
            ])
            ->schema(CashOutRequestForm::components(lockMember: true))
            ->action(function (Member $record, array $data, Action $action, MemberCashOutService $service, Component $livewire): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        function () use ($record, $data, $service): void {
                            $notes = filled($data['notes'] ?? null) ? (string) $data['notes'] : null;
                            $transactedAt = self::resolveCashOutDate($data['cash_out_date'] ?? null);

                            $request = $service->submit(
                                member: $record,
                                amount: (float) $data['amount'],
                                notes: $notes,
                            );

                            $service->accept(
                                $request,
                                auth('tenant')->id(),
                                $notes,
                                $transactedAt,
                            );
                        },
                        __('Could not create cash out'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Cash out approved'))
                    ->body(__('Member and master cash have been debited. Complete the remittance checklist under Audit & System, then clear the bank line when the statement imports.'))
                    ->success()
                    ->send();

                MemberResource::dispatchMemberDetailInsightsRefresh($livewire);
                self::refreshMembersList($livewire);
            });
    }

    public static function fundOut(): Action
    {
        return Action::make('fundOutMember')
            ->label(__('Fund out'))
            ->icon('heroicon-o-arrow-right-circle')
            ->color('primary')
            ->visible(fn (Member $record): bool => self::isTenantAdmin()
                && app(MemberMembershipPolicy::class)->canRequestFundOut($record))
            ->modalHeading(__('New fund out'))
            ->modalDescription(__('Creates and approves a fund-out on the selected date. Moves fund balance to cash with master mirrors. No bank remittance is created.'))
            ->modalWidth('2xl')
            ->fillForm(fn (Member $record): array => [
                'member_id' => $record->id,
                'fund_out_date' => self::businessDayPickerDefault(),
            ])
            ->schema(FundOutRequestForm::components(lockMember: true))
            ->action(function (Member $record, array $data, Action $action, MemberFundOutService $service, Component $livewire): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        function () use ($record, $data, $service): void {
                            $notes = filled($data['notes'] ?? null) ? (string) $data['notes'] : null;
                            $transactedAt = self::resolveCashOutDate($data['fund_out_date'] ?? null);

                            $request = $service->submit(
                                member: $record,
                                amount: (float) $data['amount'],
                                notes: $notes,
                            );

                            $service->accept(
                                $request,
                                auth('tenant')->id(),
                                $notes,
                                $transactedAt,
                            );
                        },
                        __('Could not create fund out'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Fund out approved'))
                    ->body(__('The amount was moved from the member’s fund account to cash (with master mirrors). No bank remittance is created — use cash out if money must leave the bank.'))
                    ->success()
                    ->send();

                MemberResource::dispatchMemberDetailInsightsRefresh($livewire);
                self::refreshMembersList($livewire);
            });
    }

    public static function cashOutFund(): Action
    {
        return Action::make('cashOutMemberFund')
            ->label(__('Cash out fund'))
            ->icon('heroicon-o-arrow-up-tray')
            ->color('warning')
            ->visible(fn (Member $record): bool => self::canCashOutFundAccount($record))
            ->modalHeading(__('Cash out fund balance'))
            ->modalDescription(__('Moves the member\'s fund balance to cash and records the cash-out on the date you choose. Match the bank line when the transfer clears.'))
            ->schema([
                Placeholder::make('fund_balance')
                    ->label(__('Fund balance'))
                    ->content(fn (Member $record): string => MoneyDisplay::format(
                        $record->getFundBalance(),
                        Setting::get('general', 'currency', 'USD'),
                    ) ?? '—'),
                self::cashOutDateField(),
                Textarea::make('notes')
                    ->label(__('Notes'))
                    ->rows(3)
                    ->maxLength(500)
                    ->placeholder(__('Optional note for the cash-out request')),
            ])
            ->action(function (Member $record, array $data, Action $action, MemberCashOutService $cashOuts, Component $livewire): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $cashOuts->submitFundBalanceCashOut(
                            $record,
                            filled($data['notes'] ?? null) ? (string) $data['notes'] : null,
                            self::resolveCashOutDate($data['cash_out_date'] ?? null),
                            auth('tenant')->id(),
                        ),
                        __('Cannot cash out fund'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Fund cash-out recorded'))
                    ->body(__('Fund balance was moved to cash and the cash-out was posted on the selected date.'))
                    ->success()
                    ->send();

                MemberResource::dispatchMemberDetailInsightsRefresh($livewire);
                self::refreshMembersList($livewire);
            });
    }

    public static function sendMessage(): Action
    {
        return Action::make('sendMemberMessage')
            ->label(__('Send message'))
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('info')
            ->visible(fn (Member $record): bool => self::isTenantAdmin() && $record->user_id !== null)
            ->modalHeading(fn (Member $record): string => __('Send message to :name', ['name' => $record->name]))
            ->modalWidth('lg')
            ->schema([
                TextInput::make('subject')
                    ->label(__('Subject'))
                    ->required()
                    ->maxLength(150),
                Textarea::make('body')
                    ->label(__('Message'))
                    ->required()
                    ->rows(5)
                    ->maxLength(3000),
                FileUpload::make('attachments')
                    ->label(__('Attachments'))
                    ->multiple()
                    ->disk('public')
                    ->directory('direct-messages')
                    ->openable()
                    ->downloadable()
                    ->maxFiles(5),
            ])
            ->action(function (Member $record, array $data, DirectMessagingService $messaging): void {
                $admin = auth('tenant')->user();

                if (! $admin instanceof User || $record->user_id === null) {
                    return;
                }

                $attachments = is_array($data['attachments'] ?? null)
                    ? array_values(array_filter($data['attachments'], fn ($file): bool => filled($file)))
                    : [];

                $messaging->sendAdminToMember(
                    $record,
                    $admin,
                    $data['body'],
                    $attachments,
                    subject: $data['subject'],
                );

                Notification::make()
                    ->title(__('Message sent'))
                    ->success()
                    ->send();
            });
    }

    public static function sendNotification(): Action
    {
        return Action::make('sendMemberNotification')
            ->label(__('Send notification'))
            ->icon('heroicon-o-bell')
            ->color('warning')
            ->visible(fn (Member $record): bool => self::isTenantAdmin() && $record->user_id !== null)
            ->modalHeading(fn (Member $record): string => __('Send notification to :name', ['name' => $record->name]))
            ->modalWidth('md')
            ->schema(self::portalNotificationFormSchema())
            ->action(function (Member $record, array $data, MemberPortalNotificationService $notifications): void {
                if (! $notifications->send($record, (string) $data['title'], (string) $data['body'])) {
                    Notification::make()
                        ->title(__('Notification could not be sent'))
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Notification sent'))
                    ->success()
                    ->send();
            });
    }

    public static function freeze(): Action
    {
        return Action::make('freezeMember')
            ->label(__('Freeze'))
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->visible(fn (Member $record): bool => $record->status === 'active')
            ->requiresConfirmation()
            ->modalHeading(__('Freeze membership'))
            ->modalDescription(__('Pauses contributions and cash-out. Portal becomes read-only. Loan EMIs shift one cycle per planned freeze cycle. Guarantor replacements must be accepted first if this member guarantees others.'))
            ->modalWidth('2xl')
            ->extraModalWindowAttributes([
                'class' => 'ff-tenant-form-modal-window ff-freeze-modal',
            ])
            ->fillForm(fn (Member $record): array => [
                'cycles' => 1,
                'household_mode' => MemberFreezeService::HOUSEHOLD_SELF_ONLY,
                'freeze_date' => self::businessDayPickerDefault(),
            ])
            ->schema(fn (Member $record): array => self::freezeFormSchema($record))
            ->action(function (Member $record, array $data, Action $action, Component $livewire): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => app(MemberFreezeService::class)->applyFreeze(
                            $record,
                            [
                                'cycles' => MemberFreezeService::normalizeCycles($data['cycles'] ?? null),
                                'household_mode' => (string) ($data['household_mode'] ?? MemberFreezeService::HOUSEHOLD_SELF_ONLY),
                                'temporary_parent_member_id' => $data['temporary_parent_member_id'] ?? null,
                                'reason' => (string) ($data['reason'] ?? ''),
                            ],
                            self::resolveFreezeDate($data['freeze_date'] ?? null),
                            auth('tenant')->user(),
                        ),
                        __('Cannot freeze'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Member frozen'))
                    ->success()
                    ->send();
                self::refreshMembersList($livewire);
            });
    }

    /**
     * @return list<\Filament\Schemas\Components\Component>
     */
    public static function freezeFormSchema(Member $record): array
    {
        $freezes = app(MemberFreezeService::class);

        return MemberFreezeFormSchema::requestSections(
            $record,
            $freezes->blockingReasons($record, forApprove: true),
            includeFreezeDate: true,
        );
    }

    public static function freezeDateField(): DatePicker
    {
        return DatePicker::make('freeze_date')
            ->label(__('Freeze date'))
            ->helperText(__('Recorded as the date membership was frozen.'))
            ->required()
            ->native(false)
            ->default(fn (): string => self::businessDayPickerDefault())
            ->maxDate(fn (): string => self::businessDayPickerMaxDate());
    }

    public static function resolveFreezeDate(mixed $freezeDate): Carbon
    {
        if ($freezeDate === null || $freezeDate === '') {
            return BusinessDay::today()->endOfDay();
        }

        if ($freezeDate instanceof Carbon) {
            return $freezeDate->copy()->endOfDay();
        }

        return Carbon::parse((string) $freezeDate)->endOfDay();
    }

    public static function unfreeze(): Action
    {
        return Action::make('unfreezeMember')
            ->label(__('Unfreeze'))
            ->icon('heroicon-o-play-circle')
            ->color('success')
            ->visible(fn (Member $record): bool => $record->status === 'inactive' && $record->frozen_at !== null)
            ->requiresConfirmation()
            ->modalDescription(__('Restores membership to active status.'))
            ->action(function (Member $record, Action $action, Component $livewire): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => app(MemberFreezeService::class)->applyUnfreeze($record),
                        __('Cannot unfreeze'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Member unfrozen'))->success()->send();
                self::refreshMembersList($livewire);
            });
    }

    public static function restoreSuspended(): Action
    {
        return Action::make('restoreSuspendedMember')
            ->label(__('Restore active'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('success')
            ->visible(fn (Member $record): bool => $record->status === 'inactive' && $record->frozen_at === null)
            ->requiresConfirmation()
            ->modalDescription(__('Restores membership for members on administrative or guarantor-transfer hold (not frozen).'))
            ->action(function (Member $record, Action $action, MemberStatusService $statuses, Component $livewire): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $statuses->restoreInactive($record),
                        __('Cannot restore'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Member active'))->success()->send();
                self::refreshMembersList($livewire);
            });
    }

    public static function withdraw(): Action
    {
        return Action::make('withdrawMember')
            ->label(__('Withdraw'))
            ->icon('heroicon-o-arrow-right-on-rectangle')
            ->color('danger')
            ->visible(fn (Member $record): bool => self::isTenantAdmin()
                && $record->status !== 'withdrawn')
            ->modalHeading(__('Withdraw member'))
            ->modalDescription(__('Ends membership. Active loans are early-settled from cash and fund, then residual cash is auto cash-out accepted unless payout is held for review.'))
            ->modalWidth(Width::TwoExtraLarge)
            ->schema(fn (Member $record): array => self::withdrawFormSchema($record))
            ->fillForm(function (Member $record): array {
                $hasDependents = $record->isParent()
                    && $record->dependents()->where('status', 'active')->exists();

                return [
                    'household_mode' => $hasDependents
                        ? MemberWithdrawalSettlementService::HOUSEHOLD_INCLUDE_DEPENDENTS
                        : MemberWithdrawalSettlementService::HOUSEHOLD_SELF_ONLY,
                    'withdraw_date' => self::businessDayPickerDefault(),
                    'hold_payout' => false,
                ];
            })
            ->action(function (Member $record, array $data, Action $action, MemberStatusService $statuses, Component $livewire): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $statuses->withdraw(
                            $record,
                            (string) ($data['reason'] ?? ''),
                            (bool) ($data['hold_payout'] ?? false),
                            self::resolveWithdrawDate($data['withdraw_date'] ?? null),
                            [
                                'reason' => (string) ($data['reason'] ?? ''),
                                'household_mode' => (string) ($data['household_mode'] ?? MemberWithdrawalSettlementService::HOUSEHOLD_SELF_ONLY),
                                'permanent_parent_member_id' => $data['permanent_parent_member_id'] ?? null,
                            ],
                        ),
                        __('Cannot withdraw'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Member withdrawn'))->success()->send();
                self::refreshMembersList($livewire);
            });
    }

    /**
     * @return list<\Filament\Schemas\Components\Component|\Filament\Forms\Components\Component>
     */
    public static function withdrawFormSchema(Member $member): array
    {
        $settlement = app(MemberWithdrawalSettlementService::class);

        return MemberWithdrawFormSchema::requestSections(
            $member,
            $settlement->blockingReasons($member, forApprove: true),
            includeWithdrawDate: true,
            includeHoldPayout: true,
        );
    }

    public static function withdrawDateField(): DatePicker
    {
        return DatePicker::make('withdraw_date')
            ->label(__('Withdrawal date'))
            ->helperText(__('Settlement and membership status are recorded as of this date.'))
            ->required()
            ->native(false)
            ->default(fn (): string => self::businessDayPickerDefault())
            ->maxDate(fn (): string => self::businessDayPickerMaxDate());
    }

    public static function cashOutDateField(): DatePicker
    {
        return DatePicker::make('cash_out_date')
            ->label(__('Cash-out date'))
            ->helperText(__('Fund transfer and cash-out ledger entries are recorded as of this date.'))
            ->required()
            ->native(false)
            ->default(fn (): string => self::businessDayPickerDefault())
            ->maxDate(fn (): string => self::businessDayPickerMaxDate());
    }

    public static function fundOutDateField(): DatePicker
    {
        return DatePicker::make('fund_out_date')
            ->label(__('Fund-out date'))
            ->helperText(__('Fund and cash ledger entries for this transfer are recorded as of this date.'))
            ->required()
            ->native(false)
            ->default(fn (): string => self::businessDayPickerDefault())
            ->maxDate(fn (): string => self::businessDayPickerMaxDate());
    }

    /**
     * Date-only defaults for non-native pickers store as {@code Y-m-d H:i:s}; a bare
     * Y-m-d is parsed with the current clock time, which then fails date-only maxDate.
     * Start-of-day datetime avoids that, and maxDate is end of the business day so the
     * whole calendar day remains selectable.
     */
    public static function businessDayPickerDefault(): string
    {
        return BusinessDay::today()->startOfDay()->toDateTimeString();
    }

    public static function businessDayPickerMaxDate(): string
    {
        return BusinessDay::today()->endOfDay()->toDateTimeString();
    }

    public static function resolveCashOutDate(mixed $cashOutDate): Carbon
    {
        if ($cashOutDate === null || $cashOutDate === '') {
            return BusinessDay::today()->endOfDay();
        }

        if ($cashOutDate instanceof Carbon) {
            return $cashOutDate->copy()->endOfDay();
        }

        return Carbon::parse((string) $cashOutDate)->endOfDay();
    }

    public static function resolveWithdrawDate(mixed $withdrawDate): Carbon
    {
        if ($withdrawDate === null || $withdrawDate === '') {
            return BusinessDay::today()->endOfDay();
        }

        if ($withdrawDate instanceof Carbon) {
            return $withdrawDate->copy()->endOfDay();
        }

        return Carbon::parse((string) $withdrawDate)->endOfDay();
    }

    /** @deprecated Use {@see freeze()} */
    public static function suspend(): Action
    {
        return Action::make('suspendMember')
            ->label(__('Freeze'))
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->visible(fn (Member $record): bool => $record->status === 'active')
            ->modalHeading(__('Freeze member'))
            ->modalDescription(__('Pauses membership. Portal access and contribution cycles stop. Loan repayments continue until unfrozen.'))
            ->schema(fn (Member $record): array => self::freezeFormSchema($record))
            ->action(function (Member $record, array $data, Action $action, MemberStatusService $statuses, Component $livewire): void {
                $cashOutFund = (bool) ($data['cash_out_fund'] ?? false);

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $statuses->freeze(
                            $record,
                            (string) ($data['reason'] ?? ''),
                            self::resolveFreezeDate($data['freeze_date'] ?? null),
                            $cashOutFund,
                            auth('tenant')->id(),
                        ),
                        __('Cannot freeze'),
                    )
                ) {
                    return;
                }

                $notification = Notification::make()
                    ->title(__('Member frozen'))
                    ->success();

                if ($cashOutFund) {
                    $notification->body(__('Fund balance was moved to cash and the cash-out was posted on the freeze date.'));
                }

                $notification->send();
                self::refreshMembersList($livewire);
            });
    }

    /** @deprecated Use {@see withdraw()} with hold payout */
    public static function terminate(): Action
    {
        return Action::make('terminateMember')
            ->label(__('Withdraw'))
            ->icon('heroicon-o-arrow-right-on-rectangle')
            ->color('danger')
            ->visible(fn (Member $record): bool => self::isTenantAdmin()
                && $record->status !== 'withdrawn')
            ->modalHeading(__('Withdraw member'))
            ->modalDescription(__('Ends membership and holds payout for admin review after loan settlement.'))
            ->schema(fn (Member $record): array => self::withdrawFormSchema($record))
            ->fillForm(function (Member $record): array {
                $hasDependents = $record->isParent()
                    && $record->dependents()->where('status', 'active')->exists();

                return [
                    'household_mode' => $hasDependents
                        ? MemberWithdrawalSettlementService::HOUSEHOLD_INCLUDE_DEPENDENTS
                        : MemberWithdrawalSettlementService::HOUSEHOLD_SELF_ONLY,
                    'hold_payout' => true,
                    'withdraw_date' => self::businessDayPickerDefault(),
                ];
            })
            ->action(function (Member $record, array $data, Action $action, MemberStatusService $statuses, Component $livewire): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $statuses->withdraw(
                            $record,
                            (string) ($data['reason'] ?? ''),
                            true,
                            self::resolveWithdrawDate($data['withdraw_date'] ?? null),
                            [
                                'reason' => (string) ($data['reason'] ?? ''),
                                'household_mode' => (string) ($data['household_mode'] ?? MemberWithdrawalSettlementService::HOUSEHOLD_SELF_ONLY),
                                'permanent_parent_member_id' => $data['permanent_parent_member_id'] ?? null,
                            ],
                        ),
                        __('Cannot withdraw'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Member withdrawn'))->success()->send();
                self::refreshMembersList($livewire);
            });
    }

    public static function reinstate(): Action
    {
        return Action::make('reinstateMember')
            ->label(__('Reinstate'))
            ->icon('heroicon-o-arrow-path')
            ->color('success')
            ->visible(fn (Member $record): bool => self::isTenantAdmin()
                && $record->status === 'withdrawn')
            ->requiresConfirmation()
            ->modalDescription(__('Returns member to active status and resets cash and fund balances to zero.'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->required()
                    ->rows(3)
                    ->minLength(10)
                    ->maxLength(500),
            ])
            ->action(function (Member $record, array $data, Action $action, MemberStatusService $statuses, Component $livewire): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $statuses->reinstate($record, (string) ($data['reason'] ?? '')),
                        __('Cannot reinstate'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Member reinstated'))->success()->send();
                self::refreshMembersList($livewire);
            });
    }

    public static function releasePayoutReview(): Action
    {
        return Action::make('releaseMemberPayoutReview')
            ->label(__('Release payout'))
            ->icon('heroicon-o-lock-open')
            ->color('warning')
            ->visible(fn (Member $record): bool => self::isTenantAdmin()
                && $record->status === 'withdrawn'
                && $record->payout_frozen_at !== null)
            ->requiresConfirmation()
            ->modalDescription(__('Allows settlement payouts for this member after admin review.'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->required()
                    ->rows(3)
                    ->minLength(10)
                    ->maxLength(500),
            ])
            ->action(function (Member $record, array $data, Action $action, MemberStatusService $statuses, Component $livewire): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $statuses->releasePayoutReview($record, (string) ($data['reason'] ?? '')),
                        __('Cannot release payout'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Payout review released'))->success()->send();
                self::refreshMembersList($livewire);
            });
    }

    public static function chargeAnnualSubscription(): Action
    {
        return Action::make('chargeAnnualSubscription')
            ->label(__('Charge annual sub'))
            ->icon('heroicon-o-receipt-percent')
            ->color('warning')
            ->visible(fn (Member $record): bool => self::isTenantAdmin()
                && $record->status !== 'withdrawn')
            ->modalHeading(__('Charge annual subscription fee'))
            ->modalDescription(function (Member $record): string {
                $amount = ContributionPolicySettings::annualSubscriptionFee();
                $currency = Setting::get('general', 'currency', 'USD');

                if ($amount <= 0.00001) {
                    return __('Annual subscription fee is not configured. Set it under Settings before charging :name.', [
                        'name' => $record->name,
                    ]);
                }

                return __('Debit :amount from :name\'s cash account and credit master fees.', [
                    'amount' => MoneyDisplay::format($amount, $currency) ?? '',
                    'name' => $record->name,
                ]);
            })
            ->modalSubmitActionLabel(__('Charge fee'))
            ->action(function (Member $record, Action $action, MemberAnnualSubscriptionFeeService $fees, Component $livewire): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $fees->charge($record),
                        __('Cannot charge annual subscription fee'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Annual subscription fee charged'))
                    ->success()
                    ->send();

                self::refreshMembersList($livewire);
            });
    }

    public static function adminOverride(): Action
    {
        return Action::make('adminMemberOverride')
            ->label(__('Admin override'))
            ->icon('heroicon-o-shield-check')
            ->color('warning')
            ->visible(fn (Member $record): bool => auth('tenant')->user()?->is_admin === true)
            ->modalHeading(__('Admin eligibility override'))
            ->modalDescription(__('Creates standing loan eligibility overrides for this member. Overrides are logged in the audit trail.'))
            ->schema([
                Select::make('gates')
                    ->label(__('Gates to override'))
                    ->options(LoanEligibilityGate::labels())
                    ->multiple()
                    ->required()
                    ->default(fn (Member $record): array => array_keys(
                        app(LoanEligibilityService::class)->getFailedGates($record)
                    )),
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->required()
                    ->rows(3)
                    ->minLength(10)
                    ->helperText(__('Minimum 10 characters. Recorded in the audit log.')),
            ])
            ->action(function (Member $record, array $data, Action $action, LoanEligibilityOverrideService $overrides, Component $livewire): void {
                $reason = trim((string) ($data['reason'] ?? ''));

                if (Str::length($reason) < 10) {
                    ActionModalFailure::present(
                        $action,
                        __('Override reason must be at least 10 characters.'),
                        __('Cannot save override'),
                    );
                }

                /** @var list<string> $gates */
                $gates = array_values(array_filter((array) ($data['gates'] ?? [])));

                if ($gates === []) {
                    ActionModalFailure::present(
                        $action,
                        __('Select at least one eligibility gate to override.'),
                        __('Cannot save override'),
                    );
                }

                $overrides->recordMany((int) $record->id, $gates, $reason);

                $gateLabels = array_map(
                    fn (string $gate): string => LoanEligibilityGate::labels()[$gate] ?? $gate,
                    $gates,
                );

                Notification::make()
                    ->title(__('Eligibility override saved'))
                    ->body(implode(', ', $gateLabels))
                    ->success()
                    ->send();

                self::refreshMembersList($livewire);
            });
    }

    public static function freezeBulk(): BulkAction
    {
        return BulkAction::make('freezeSelectedMembers')
            ->label(__('Freeze'))
            ->icon('heroicon-o-pause-circle')
            ->color('warning')
            ->requiresConfirmation()
            ->modalDescription(__('Freezes selected active members for a 1-cycle plan (self only). Members with blockers are skipped.'))
            ->action(function (Collection $records, Component $livewire): void {
                $freezes = app(MemberFreezeService::class);
                $frozen = 0;
                $failed = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Member || $record->status !== 'active') {
                        continue;
                    }

                    try {
                        $freezes->applyFreeze($record, [
                            'cycles' => 1,
                            'household_mode' => MemberFreezeService::HOUSEHOLD_SELF_ONLY,
                            'reason' => __('Bulk freeze'),
                        ]);
                        $frozen++;
                    } catch (\Throwable) {
                        $failed++;
                    }
                }

                Notification::make()
                    ->title(__('Freeze complete'))
                    ->body(__(':count frozen · :failed could not be updated', [
                        'count' => $frozen,
                        'failed' => $failed,
                    ]))
                    ->color($failed > 0 ? 'warning' : 'success')
                    ->send();

                self::refreshMembersList($livewire);
            });
    }

    /** @deprecated Use {@see freezeBulk()} */
    public static function suspendBulk(): BulkAction
    {
        return self::freezeBulk();
    }

    public static function contributeBulk(): BulkAction
    {
        return BulkAction::make('contributeSelectedMembers')
            ->label(__('Contribute'))
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->schema([
                Select::make('cycle')
                    ->label(__('Cycle'))
                    ->options(fn (): array => app(ContributionCycleService::class)
                        ->contributionCycleSelectOptionsForBulk())
                    ->required(),
                ContributionCycleHeaderActions::collectOldestArrearsFirstToggle(),
            ])
            ->fillForm(function (): array {
                $cycles = app(ContributionCycleService::class);
                [$month, $year] = $cycles->currentOpenPeriod();

                return [
                    'cycle' => $cycles->contributionCycleKey($month, $year),
                    'collect_oldest_arrears_first' => true,
                ];
            })
            ->action(function (Collection $records, array $data, Component $livewire): void {
                $cycles = app(ContributionCycleService::class);
                [$month, $year] = $cycles->parseContributionCycleKey($data['cycle']);
                $collectOldestArrearsFirst = (bool) ($data['collect_oldest_arrears_first'] ?? true);
                $applied = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Member || ! app(MemberMembershipPolicy::class)->canAdminContribute($record)) {
                        $skipped++;

                        continue;
                    }

                    $outcome = $cycles->applyContributionForMemberForPeriod(
                        $record,
                        $month,
                        $year,
                        $collectOldestArrearsFirst,
                    );

                    if (in_array($outcome, ['applied', 'partial'], true)) {
                        $applied++;
                    } else {
                        $skipped++;
                    }
                }

                self::notifyBulkOutcome(__('Contribute complete'), $applied, $skipped);
                self::refreshMembersList($livewire);
            });
    }

    public static function repaymentBulk(): BulkAction
    {
        return BulkAction::make('repaySelectedMembers')
            ->label(__('Repayment'))
            ->icon('heroicon-o-currency-dollar')
            ->color('primary')
            ->visible(fn (): bool => self::isTenantAdmin())
            ->schema([
                Select::make('cycle')
                    ->label(__('Cycle'))
                    ->options(fn (): array => app(ContributionCycleService::class)
                        ->contributionCycleSelectOptionsForBulk())
                    ->required(),
                ContributionCycleHeaderActions::collectOldestArrearsFirstToggle(),
            ])
            ->fillForm(fn (): array => [
                ...ContributionCycleHeaderActions::defaultPeriod(),
                'collect_oldest_arrears_first' => true,
            ])
            ->action(function (Collection $records, array $data, Component $livewire): void {
                $cycles = app(ContributionCycleService::class);
                [$month, $year] = $cycles->parseContributionCycleKey($data['cycle']);
                $collectOldestArrearsFirst = (bool) ($data['collect_oldest_arrears_first'] ?? true);
                $catalog = app(LoanEmiCollectionCatalogService::class);
                $collected = 0;
                $partial = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Member || ! $record->hasActiveLoanRepaymentObligation()) {
                        $skipped++;

                        continue;
                    }

                    $outcome = $catalog->applyForMember(
                        $record,
                        $month,
                        $year,
                        $collectOldestArrearsFirst,
                    );

                    match ($outcome) {
                        'collected' => $collected++,
                        'partial' => $partial++,
                        default => $skipped++,
                    };
                }

                Notification::make()
                    ->title(__('Repayment complete'))
                    ->body(__(':collected collected · :partial partial · :skipped skipped', [
                        'collected' => $collected,
                        'partial' => $partial,
                        'skipped' => $skipped,
                    ]))
                    ->color($collected > 0 ? 'success' : 'warning')
                    ->send();

                if ($collected > 0 || $partial > 0) {
                    self::refreshMembersList($livewire);
                }
            });
    }

    public static function adjustCashBulk(): BulkAction
    {
        return self::memberAccountAdjustmentBulk(
            name: 'adjustCashSelectedMembers',
            label: __('Adjust cash'),
            icon: 'heroicon-o-banknotes',
            resolveAccount: fn (Member $record) => self::resolveMemberAccount($record, 'cash'),
            modalDescription: __('Post the same manual credit or debit to each selected member\'s cash account.'),
        );
    }

    public static function adjustFundBulk(): BulkAction
    {
        return self::memberAccountAdjustmentBulk(
            name: 'adjustFundSelectedMembers',
            label: __('Adjust fund'),
            icon: 'heroicon-o-building-library',
            resolveAccount: fn (Member $record) => self::resolveMemberAccount($record, 'fund'),
            modalDescription: __('Post the same manual credit or debit to each selected member\'s fund account.'),
        );
    }

    public static function sendMessageBulk(): BulkAction
    {
        return BulkAction::make('sendMessageSelectedMembers')
            ->label(__('Send message'))
            ->icon('heroicon-o-chat-bubble-left-right')
            ->color('info')
            ->visible(fn (): bool => self::isTenantAdmin())
            ->modalHeading(__('Send message to selected members'))
            ->modalDescription(__('The same message and attachments are delivered to each selected member with a portal login.'))
            ->modalWidth('lg')
            ->schema([
                TextInput::make('subject')
                    ->label(__('Subject'))
                    ->required()
                    ->maxLength(150),
                Textarea::make('body')
                    ->label(__('Message'))
                    ->required()
                    ->rows(5)
                    ->maxLength(3000),
                FileUpload::make('attachments')
                    ->label(__('Attachments'))
                    ->multiple()
                    ->disk('public')
                    ->directory('direct-messages')
                    ->openable()
                    ->downloadable()
                    ->maxFiles(5),
            ])
            ->action(function (Collection $records, array $data, DirectMessagingService $messaging): void {
                $admin = auth('tenant')->user();

                if (! $admin instanceof User) {
                    return;
                }

                $attachments = is_array($data['attachments'] ?? null)
                    ? array_values(array_filter($data['attachments'], fn ($file): bool => filled($file)))
                    : [];

                $sent = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Member || $record->user_id === null) {
                        $skipped++;

                        continue;
                    }

                    if (
                        $messaging->sendAdminToMember(
                            $record,
                            $admin,
                            $data['body'],
                            $attachments,
                            suppressAdminToast: true,
                            subject: $data['subject'],
                        )
                    ) {
                        $sent++;
                    } else {
                        $skipped++;
                    }
                }

                if ($sent === 0) {
                    Notification::make()
                        ->title(__('No messages sent'))
                        ->body(__('No eligible members with portal accounts were selected.'))
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Messages sent'))
                    ->body(__(':sent delivered · :skipped skipped', [
                        'sent' => $sent,
                        'skipped' => $skipped,
                    ]))
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function sendNotificationBulk(): BulkAction
    {
        return BulkAction::make('sendNotificationSelectedMembers')
            ->label(__('Send notification'))
            ->icon('heroicon-o-bell')
            ->color('warning')
            ->visible(fn (): bool => self::isTenantAdmin())
            ->modalHeading(__('Send notification to selected members'))
            ->modalDescription(__('Posts the same in-app notification to each selected member with a portal login.'))
            ->modalWidth('md')
            ->schema(self::portalNotificationFormSchema())
            ->action(function (Collection $records, array $data, MemberPortalNotificationService $notifications): void {
                $result = $notifications->sendToMany($records, (string) $data['title'], (string) $data['body']);

                if ($result['sent'] === 0) {
                    Notification::make()
                        ->title(__('No notifications sent'))
                        ->body(__('No eligible members with portal accounts were selected.'))
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__('Notifications sent'))
                    ->body(__(':sent delivered · :skipped skipped', [
                        'sent' => $result['sent'],
                        'skipped' => $result['skipped'],
                    ]))
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function restoreSuspendedBulk(): BulkAction
    {
        return BulkAction::make('restoreSuspendedSelectedMembers')
            ->label(__('Active'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('Sets selected inactive members back to active.'))
            ->action(function (Collection $records, MemberStatusService $statuses, Component $livewire): void {
                $restored = 0;
                $failed = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Member || $record->status !== 'inactive' || $record->frozen_at !== null) {
                        continue;
                    }

                    try {
                        $statuses->restoreInactive($record);
                        $restored++;
                    } catch (\Throwable) {
                        $failed++;
                    }
                }

                self::notifyBulkOutcome(__('Active complete'), $restored, $failed);
                self::refreshMembersList($livewire);
            });
    }

    /** @deprecated Use {@see withdrawBulk()} */
    public static function terminateBulk(): BulkAction
    {
        return self::withdrawBulk();
    }

    public static function withdrawBulk(): BulkAction
    {
        return BulkAction::make('withdrawSelectedMembers')
            ->label(__('Withdraw'))
            ->icon('heroicon-o-arrow-right-on-rectangle')
            ->color('danger')
            ->visible(fn (): bool => self::isTenantAdmin())
            ->modalHeading(__('Withdraw selected members'))
            ->modalDescription(__('Early-settles active loans and auto-accepts cash-out for remaining balances (or holds payout). Parents with dependents withdraw the household.'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->rows(3)
                    ->maxLength(500)
                    ->helperText(__('Optional note stored in the audit log for each member.')),
                Toggle::make('hold_payout')
                    ->label(__('Hold payout for admin review'))
                    ->default(false),
                self::withdrawDateField(),
            ])
            ->action(function (Collection $records, array $data, MemberStatusService $statuses, Component $livewire): void {
                $reason = (string) ($data['reason'] ?? '');
                $holdPayout = (bool) ($data['hold_payout'] ?? false);
                $withdrawAt = self::resolveWithdrawDate($data['withdraw_date'] ?? null);
                $withdrawn = 0;
                $failed = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Member || $record->status === 'withdrawn') {
                        continue;
                    }

                    $hasDependents = $record->isParent()
                        && $record->dependents()->where('status', 'active')->exists();

                    try {
                        $statuses->withdraw($record, $reason, $holdPayout, $withdrawAt, [
                            'reason' => $reason,
                            'household_mode' => $hasDependents
                                ? MemberWithdrawalSettlementService::HOUSEHOLD_INCLUDE_DEPENDENTS
                                : MemberWithdrawalSettlementService::HOUSEHOLD_SELF_ONLY,
                        ]);
                        $withdrawn++;
                    } catch (\Throwable) {
                        $failed++;
                    }
                }

                self::notifyBulkOutcome(__('Withdraw complete'), $withdrawn, $failed);
                self::refreshMembersList($livewire);
            });
    }

    public static function deleteBulk(): BulkAction
    {
        return DeleteBulkAction::make()
            ->after(fn (Component $livewire): mixed => self::refreshMembersList($livewire));
    }

    public static function chargeAnnualSubscriptionBulk(): BulkAction
    {
        return BulkAction::make('chargeAnnualSubscriptionSelectedMembers')
            ->label(__('Charge annual sub'))
            ->icon('heroicon-o-receipt-percent')
            ->color('warning')
            ->visible(fn (): bool => self::isTenantAdmin())
            ->requiresConfirmation()
            ->modalHeading(__('Charge annual subscription fee'))
            ->modalDescription(function (): string {
                $amount = ContributionPolicySettings::annualSubscriptionFee();
                $currency = Setting::get('general', 'currency', 'USD');

                if ($amount <= 0.00001) {
                    return __('Annual subscription fee is not configured. Set it under Settings before charging selected members.');
                }

                return __('Debit :amount from each eligible member\'s cash account and credit master fees.', [
                    'amount' => MoneyDisplay::format($amount, $currency) ?? '',
                ]);
            })
            ->action(function (Collection $records, MemberAnnualSubscriptionFeeService $fees, Component $livewire): void {
                $charged = 0;
                $failed = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Member || $record->status === 'withdrawn') {
                        continue;
                    }

                    try {
                        $fees->charge($record);
                        $charged++;
                    } catch (\Throwable) {
                        $failed++;
                    }
                }

                self::notifyBulkOutcome(__('Annual subscription fee complete'), $charged, $failed);
                self::refreshMembersList($livewire);
            });
    }

    public static function adminOverrideBulk(): BulkAction
    {
        return BulkAction::make('adminOverrideSelectedMembers')
            ->label(__('Admin override'))
            ->icon('heroicon-o-shield-check')
            ->color('warning')
            ->visible(fn (): bool => self::isTenantAdmin())
            ->modalHeading(__('Admin eligibility override'))
            ->modalDescription(__('Creates the same standing loan eligibility overrides for each selected member. Overrides are logged in the audit trail.'))
            ->schema([
                Select::make('gates')
                    ->label(__('Gates to override'))
                    ->options(LoanEligibilityGate::labels())
                    ->multiple()
                    ->required(),
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->required()
                    ->rows(3)
                    ->minLength(10)
                    ->helperText(__('Minimum 10 characters. Recorded in the audit log.')),
            ])
            ->action(function (Collection $records, array $data, LoanEligibilityOverrideService $overrides, Component $livewire): void {
                $reason = trim((string) ($data['reason'] ?? ''));

                if (Str::length($reason) < 10) {
                    Notification::make()
                        ->title(__('Override reason must be at least 10 characters.'))
                        ->warning()
                        ->send();

                    return;
                }

                /** @var list<string> $gates */
                $gates = array_values(array_filter((array) ($data['gates'] ?? [])));

                if ($gates === []) {
                    Notification::make()
                        ->title(__('Select at least one eligibility gate to override.'))
                        ->warning()
                        ->send();

                    return;
                }

                $saved = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Member) {
                        continue;
                    }

                    $overrides->recordMany((int) $record->id, $gates, $reason);
                    $saved++;
                }

                Notification::make()
                    ->title(__('Eligibility override saved'))
                    ->body(__(':count member(s) updated.', ['count' => $saved]))
                    ->success()
                    ->send();

                self::refreshMembersList($livewire);
            });
    }

    private static function memberAccountAdjustmentBulk(
        string $name,
        string $label,
        string $icon,
        Closure $resolveAccount,
        string $modalDescription,
    ): BulkAction {
        return BulkAction::make($name)
            ->label($label)
            ->icon($icon)
            ->visible(fn (): bool => self::isTenantAdmin())
            ->modalHeading($label)
            ->modalDescription($modalDescription)
            ->modalWidth('md')
            ->schema([
                Select::make('direction')
                    ->label(__('Direction'))
                    ->options([
                        'credit' => __('Credit'),
                        'debit' => __('Debit'),
                    ])
                    ->required()
                    ->default('credit'),
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
            ])
            ->action(function (Collection $records, array $data, AccountingService $accounting, Component $livewire) use ($resolveAccount): void {
                $transactedAt = Carbon::parse($data['transacted_at']);
                $amount = (float) $data['amount'];
                $description = (string) $data['description'];
                $posted = 0;
                $failed = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Member) {
                        continue;
                    }

                    try {
                        $account = $resolveAccount($record);

                        if ($data['direction'] === 'debit') {
                            $accounting->postManualDebit($account, $amount, $description, $transactedAt);
                        } else {
                            $accounting->postManualCredit($account, $amount, $description, $transactedAt);
                        }

                        $posted++;
                    } catch (\Throwable) {
                        $failed++;
                    }
                }

                self::notifyBulkOutcome(__('Adjustment complete'), $posted, $failed);
                self::refreshMembersList($livewire);
            });
    }

    /**
     * @return list<TextInput|Textarea>
     */
    private static function portalNotificationFormSchema(): array
    {
        return [
            TextInput::make('title')
                ->label(__('Title'))
                ->required()
                ->maxLength(150),
            Textarea::make('body')
                ->label(__('Message'))
                ->required()
                ->rows(4)
                ->maxLength(1000),
        ];
    }

    private static function notifyBulkOutcome(string $title, int $succeeded, int $failed): void
    {
        Notification::make()
            ->title($title)
            ->body(__(':succeeded succeeded · :failed could not be completed', [
                'succeeded' => $succeeded,
                'failed' => $failed,
            ]))
            ->color($failed > 0 ? 'warning' : 'success')
            ->send();
    }

    private static function memberAccountAdjustment(
        string $name,
        string $label,
        string $icon,
        Closure $resolveAccount,
        string $modalDescription,
    ): Action {
        return Action::make($name)
            ->label($label)
            ->icon($icon)
            ->visible(fn (): bool => self::isTenantAdmin())
            ->modalHeading($label)
            ->modalDescription($modalDescription)
            ->modalWidth('md')
            ->schema([
                Select::make('direction')
                    ->label(__('Direction'))
                    ->options([
                        'credit' => __('Credit'),
                        'debit' => __('Debit'),
                    ])
                    ->required()
                    ->default('credit'),
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
            ])
            ->action(function (Member $record, array $data, Action $action, AccountingService $accounting, Component $livewire) use ($resolveAccount): void {
                $account = $resolveAccount($record);
                $transactedAt = Carbon::parse($data['transacted_at']);
                $amount = (float) $data['amount'];
                $description = (string) $data['description'];

                $callback = $data['direction'] === 'debit'
                    ? fn () => $accounting->postManualDebit($account, $amount, $description, $transactedAt)
                    : fn () => $accounting->postManualCredit($account, $amount, $description, $transactedAt);

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        $callback,
                        __('Adjustment failed'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Adjustment posted'))
                    ->success()
                    ->send();

                self::refreshMembersList($livewire);
            });
    }

    private static function resolveMemberAccount(Member $record, string $type): Account
    {
        $record->loadMissing($type === 'cash' ? 'cashAccount' : 'fundAccount');

        $account = $type === 'cash' ? $record->cashAccount : $record->fundAccount;

        if ($account === null) {
            app(AccountingService::class)->createMemberAccounts($record);
            $record->load($type === 'cash' ? 'cashAccount' : 'fundAccount');
            $account = $type === 'cash' ? $record->cashAccount : $record->fundAccount;
        }

        if ($account === null) {
            throw new \InvalidArgumentException(__('Member :type account is not configured.', ['type' => $type]));
        }

        return $account;
    }

    private static function membershipApplicationCreateUrl(Member $record): string
    {
        return self::dependentApplicationCreateUrl($record->householdHead());
    }

    public static function dependentApplicationCreateUrl(Member $householdHead): string
    {
        return MembershipApplicationResource::getUrl('create').'?parent_member_id='.$householdHead->id;
    }

    private static function isTenantAdmin(): bool
    {
        return (bool) auth('tenant')->user()?->is_admin;
    }

    private static function canCashOutFundAccount(Member $record): bool
    {
        if (! self::isTenantAdmin() || $record->status === 'active') {
            return false;
        }

        if (! app(MemberMembershipPolicy::class)->canReceivePayout($record)) {
            return false;
        }

        $record->loadMissing('fundAccount');

        return $record->getFundBalance() > 0.01;
    }

    private static function canOfferFundCashOutOnFreeze(Member $record): bool
    {
        if (! self::isTenantAdmin() || $record->status !== 'active') {
            return false;
        }

        if (! app(MemberMembershipPolicy::class)->canReceivePayout($record)) {
            return false;
        }

        $record->loadMissing('fundAccount');

        return $record->getFundBalance() > 0.01;
    }

    private static function refreshMembersList(Component $livewire): void
    {
        MemberResource::dispatchInsightsRefresh($livewire);
        MemberResource::dispatchMemberDetailInsightsRefresh($livewire);

        // Synchronously refresh ViewMember workspace stats (cash/fund). Livewire
        // bus events alone are easy to miss during the same request / tests.
        if (method_exists($livewire, 'refreshMemberFromInsights')) {
            $memberId = null;

            if (method_exists($livewire, 'getRecord')) {
                $record = $livewire->getRecord();
                if ($record instanceof Member) {
                    $memberId = (int) $record->getKey();
                }
            }

            if ($memberId !== null) {
                $livewire->refreshMemberFromInsights($memberId);
            }
        }

        if (method_exists($livewire, 'resetTable')) {
            $livewire->resetTable();
        }
    }
}
