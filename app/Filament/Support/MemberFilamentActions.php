<?php

declare(strict_types=1);

namespace App\Filament\Support;

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
use App\Services\Loans\LoanRepaymentService;
use App\Services\MemberAnnualSubscriptionFeeService;
use App\Services\MemberStatusService;
use App\Services\Tenant\DirectMessagingService;
use App\Services\Tenant\MemberPortalNotificationService;
use App\Support\BusinessDay;
use App\Support\ContributionPolicySettings;
use App\Support\LoanEligibilityGate;
use Carbon\Carbon;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\DateTimePicker;
use Filament\Forms\Components\FileUpload;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Str;
use Livewire\Component;

final class MemberFilamentActions
{
    /**
     * @return list<Action>
     */
    public static function forMemberListRow(): array
    {
        return [
            self::contribute(),
            self::repayment(),
            self::application(),
            self::adjustCash(),
            self::adjustFund(),
            self::sendMessage(),
            self::sendNotification(),
            self::suspend(),
            self::restoreSuspended(),
            self::terminate(),
            self::chargeAnnualSubscription(),
            self::adminOverride(),
            self::delete(),
        ];
    }

    /**
     * Row actions for dependents listed on a household head's member edit page.
     *
     * @return list<Action>
     */
    public static function forHouseholdDependentMemberRow(): array
    {
        return [
            self::contribute(),
            self::repayment(),
            self::adjustCash(),
            self::adjustFund(),
            self::sendMessage(),
            self::sendNotification(),
            self::suspend(),
            self::restoreSuspended(),
            self::terminate(),
            self::chargeAnnualSubscription(),
            self::adminOverride(),
            self::delete(),
        ];
    }

    /**
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
            self::suspendBulk(),
            self::restoreSuspendedBulk(),
            self::terminateBulk(),
            self::chargeAnnualSubscriptionBulk(),
            self::adminOverrideBulk(),
            self::deleteBulk(),
        ];
    }

    public static function contribute(): Action
    {
        return Action::make('contributeMember')
            ->label(__('Contribute'))
            ->icon('heroicon-o-banknotes')
            ->color('success')
            ->visible(function (Member $record): bool {
                $cycles = app(ContributionCycleService::class);

                return $record->status === 'active'
                    && $cycles->contributionCycleSelectOptionsForMember($record) !== [];
            })
            ->schema([
                Select::make('cycle')
                    ->label(__('Cycle'))
                    ->options(fn (Member $record): array => app(ContributionCycleService::class)
                        ->contributionCycleSelectOptionsForMember($record))
                    ->required(),
            ])
            ->fillForm(fn (Member $record): array => [
                'cycle' => app(ContributionCycleService::class)
                    ->defaultContributionCycleKeyForMember($record),
            ])
            ->action(function (Member $record, array $data, Component $livewire): void {
                $cycles = app(ContributionCycleService::class);
                [$month, $year] = $cycles->parseContributionCycleKey($data['cycle']);
                $outcome = $cycles->applyContributionForMemberForPeriod($record, $month, $year);

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
            ->requiresConfirmation()
            ->modalHeading(__('Pay loan installment from cash'))
            ->modalDescription(fn (Member $record): string => app(LoanRepaymentService::class)
                ->openPeriodRepaymentModalDescription($record))
            ->action(function (Member $record, LoanRepaymentService $repayments, Component $livewire): void {
                $result = $repayments->applyOpenPeriodRepaymentForMember($record);

                $notification = Notification::make()
                    ->title(match ($result) {
                        'applied' => __('Repayment applied'),
                        'insufficient' => __('Insufficient cash'),
                        default => __('Nothing to apply'),
                    })
                    ->body($result === 'skipped'
                        ? $repayments->openPeriodSkipMessage($record)
                        : null);

                match ($result) {
                    'applied' => $notification->success(),
                    'insufficient' => $notification->warning(),
                    default => $notification->info(),
                };

                $notification->send();

                if ($result === 'applied') {
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

    public static function suspend(): Action
    {
        return Action::make('suspendMember')
            ->label(__('Suspend'))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (Member $record): bool => ! in_array($record->status, ['suspended', 'withdrawn', 'terminated'], true))
            ->requiresConfirmation()
            ->modalDescription(__('Blocks portal access and admin-approved transactions until suspension is lifted.'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->rows(3)
                    ->maxLength(500)
                    ->helperText(__('Optional note stored in the audit log.')),
            ])
            ->action(function (Member $record, array $data, Action $action, MemberStatusService $statuses, Component $livewire): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $statuses->suspend($record, (string) ($data['reason'] ?? '')),
                        __('Cannot suspend'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Member suspended'))->success()->send();
                self::refreshMembersList($livewire);
            });
    }

    public static function restoreSuspended(): Action
    {
        return Action::make('restoreSuspendedMember')
            ->label(__('Restore suspended'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('success')
            ->visible(fn (Member $record): bool => $record->status === 'suspended')
            ->requiresConfirmation()
            ->modalDescription(__('Restores active membership and portal access.'))
            ->action(function (Member $record, Action $action, MemberStatusService $statuses, Component $livewire): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $statuses->restoreSuspended($record),
                        __('Cannot restore'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Member restored to active'))->success()->send();
                self::refreshMembersList($livewire);
            });
    }

    public static function terminate(): Action
    {
        return Action::make('terminateMember')
            ->label(__('Terminate'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (Member $record): bool => self::isTenantAdmin()
                && ! in_array($record->status, ['withdrawn', 'terminated'], true))
            ->requiresConfirmation()
            ->modalDescription(__('Permanently ends membership and blocks portal access. This is recorded in the audit log.'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->rows(3)
                    ->maxLength(500)
                    ->helperText(__('Optional note stored in the audit log.')),
            ])
            ->action(function (Member $record, array $data, Action $action, MemberStatusService $statuses, Component $livewire): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $statuses->terminate($record, (string) ($data['reason'] ?? '')),
                        __('Cannot terminate'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Member terminated'))->success()->send();
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
                && ! in_array($record->status, ['withdrawn', 'terminated'], true))
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

    public static function suspendBulk(): BulkAction
    {
        return BulkAction::make('suspendSelectedMembers')
            ->label(__('Suspend'))
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->requiresConfirmation()
            ->modalDescription(__('Blocks portal access for selected members until suspension is lifted.'))
            ->action(function (Collection $records, MemberStatusService $statuses, Component $livewire): void {
                $suspended = 0;
                $failed = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Member || in_array($record->status, ['suspended', 'withdrawn', 'terminated'], true)) {
                        continue;
                    }

                    try {
                        $statuses->suspend($record);
                        $suspended++;
                    } catch (\Throwable) {
                        $failed++;
                    }
                }

                Notification::make()
                    ->title(__('Suspend complete'))
                    ->body(__(':suspended suspended · :failed could not be suspended', [
                        'suspended' => $suspended,
                        'failed' => $failed,
                    ]))
                    ->color($failed > 0 ? 'warning' : 'success')
                    ->send();

                self::refreshMembersList($livewire);
            });
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
            ])
            ->fillForm(function (): array {
                $cycles = app(ContributionCycleService::class);
                [$month, $year] = $cycles->currentOpenPeriod();

                return ['cycle' => $cycles->contributionCycleKey($month, $year)];
            })
            ->action(function (Collection $records, array $data, Component $livewire): void {
                $cycles = app(ContributionCycleService::class);
                [$month, $year] = $cycles->parseContributionCycleKey($data['cycle']);
                $applied = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Member || $record->status !== 'active') {
                        $skipped++;

                        continue;
                    }

                    $outcome = $cycles->applyContributionForMemberForPeriod($record, $month, $year);

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
            ->requiresConfirmation()
            ->modalHeading(__('Apply open-period repayment'))
            ->modalDescription(__('Debits member cash for the current open-period EMI where applicable.'))
            ->action(function (Collection $records, LoanRepaymentService $repayments, Component $livewire): void {
                $applied = 0;
                $insufficient = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Member || ! $record->hasActiveLoanRepaymentObligation()) {
                        $skipped++;

                        continue;
                    }

                    $result = $repayments->applyOpenPeriodRepaymentForMember($record);

                    match ($result) {
                        'applied' => $applied++,
                        'insufficient' => $insufficient++,
                        default => $skipped++,
                    };
                }

                Notification::make()
                    ->title(__('Repayment complete'))
                    ->body(__(':applied applied · :insufficient insufficient · :skipped skipped', [
                        'applied' => $applied,
                        'insufficient' => $insufficient,
                        'skipped' => $skipped,
                    ]))
                    ->color($applied > 0 ? 'success' : 'warning')
                    ->send();

                if ($applied > 0) {
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
            ->label(__('Restore suspended'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('Restores active membership and portal access for selected suspended members.'))
            ->action(function (Collection $records, MemberStatusService $statuses, Component $livewire): void {
                $restored = 0;
                $failed = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Member || $record->status !== 'suspended') {
                        continue;
                    }

                    try {
                        $statuses->restoreSuspended($record);
                        $restored++;
                    } catch (\Throwable) {
                        $failed++;
                    }
                }

                self::notifyBulkOutcome(__('Restore suspended complete'), $restored, $failed);
                self::refreshMembersList($livewire);
            });
    }

    public static function terminateBulk(): BulkAction
    {
        return BulkAction::make('terminateSelectedMembers')
            ->label(__('Terminate'))
            ->icon('heroicon-o-x-circle')
            ->color('danger')
            ->visible(fn (): bool => self::isTenantAdmin())
            ->requiresConfirmation()
            ->modalDescription(__('Permanently ends membership for selected members. This is recorded in the audit log.'))
            ->schema([
                Textarea::make('reason')
                    ->label(__('Reason'))
                    ->rows(3)
                    ->maxLength(500)
                    ->helperText(__('Optional note stored in the audit log for each member.')),
            ])
            ->action(function (Collection $records, array $data, MemberStatusService $statuses, Component $livewire): void {
                $reason = (string) ($data['reason'] ?? '');
                $terminated = 0;
                $failed = 0;

                foreach ($records as $record) {
                    if (! $record instanceof Member || in_array($record->status, ['withdrawn', 'terminated'], true)) {
                        continue;
                    }

                    try {
                        $statuses->terminate($record, $reason);
                        $terminated++;
                    } catch (\Throwable) {
                        $failed++;
                    }
                }

                self::notifyBulkOutcome(__('Terminate complete'), $terminated, $failed);
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
                    if (! $record instanceof Member || in_array($record->status, ['withdrawn', 'terminated'], true)) {
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

    private static function refreshMembersList(Component $livewire): void
    {
        MemberResource::dispatchInsightsRefresh($livewire);

        if (method_exists($livewire, 'resetTable')) {
            $livewire->resetTable();
        }
    }
}
