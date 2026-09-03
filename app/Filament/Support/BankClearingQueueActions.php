<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Filament\Tenant\Support\TenantPortalViewModal;
use App\Filament\Tenant\Support\ViewBankTransactionAction;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\SmsTransaction;
use App\Services\BankClearingMatchService;
use App\Services\BankClearingQueueService;
use App\Services\BankImportPostAsService;
use App\Services\FundFlowService;
use App\Services\PendingOperationalClearanceDeletionService;
use App\Services\SmsBankClearingMatchService;
use App\Support\BankClearing\BankClearingQueuePresenter;
use App\Support\BankTransactionDeletion;
use App\Support\ContributionPolicySettings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\DatePicker;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;
use Throwable;

final class BankClearingQueueActions
{
    /**
     * Single Actions dropdown; children are slice-scoped and visibility-gated per row.
     *
     * @return array<int, ActionGroup>
     */
    public static function groupedRecordActions(?string $queueFilter = null): array
    {
        return TableRecordActionGroups::wrap(self::recordActions($queueFilter));
    }

    /**
     * @return array<int, Action>
     */
    public static function recordActions(?string $queueFilter = null): array
    {
        $filter = BankClearingTabRegistry::normalizeQueueFilter($queueFilter);
        $includeBankFile = in_array($filter, [BankClearingTabRegistry::FILTER_ALL, BankClearingTabRegistry::FILTER_BANK_FILE], true);
        $includeOperations = in_array($filter, [BankClearingTabRegistry::FILTER_ALL, BankClearingTabRegistry::FILTER_OPERATIONS], true);
        $actions = [];

        if ($includeBankFile) {
            array_push($actions, ...self::bankFileResolveActions());
            $actions[] = self::matchToMultipleOperations();
            $actions[] = self::matchToSms();
            $actions[] = self::matchToMultipleSms();
        }

        if ($includeOperations) {
            if (! $includeBankFile) {
                $actions[] = self::autoMatch();
            }

            $actions[] = self::matchToBankLine();
            $actions[] = self::matchToMultipleBankLines();
            $actions[] = self::clearWithoutEvidence();
        }

        $actions[] = self::view();

        if ($includeBankFile) {
            array_push($actions, ...self::bankFileRemoveActions());
        }

        if ($includeOperations) {
            array_push($actions, ...self::operationsRemoveActions());
        }

        return $actions;
    }

    /**
     * @return array<int, BulkAction|DeleteBulkAction>
     */
    public static function toolbarBulkActions(?string $queueFilter = null): array
    {
        $filter = BankClearingTabRegistry::normalizeQueueFilter($queueFilter);

        $actions = [];

        if (in_array($filter, [BankClearingTabRegistry::FILTER_ALL, BankClearingTabRegistry::FILTER_OPERATIONS], true)) {
            $actions[] = self::matchAllUniqueBulk();
            $actions[] = self::matchSelectedBulk();
            $actions[] = self::clearWithoutEvidenceBulk();
        }

        if (in_array($filter, [BankClearingTabRegistry::FILTER_ALL, BankClearingTabRegistry::FILTER_BANK_FILE], true)) {
            $actions[] = self::ignoreBulk();
        }

        $actions[] = self::deleteBulk();

        return $actions;
    }

    /**
     * @return array<int, Action>
     */
    public static function bankFileResolveActions(): array
    {
        return [
            self::postAs(),
            self::autoMatch(),
        ];
    }

    /**
     * @return array<int, Action>
     */
    public static function operationsResolveActions(): array
    {
        return [
            self::autoMatch(),
            self::matchToBankLine(),
            self::matchToMultipleBankLines(),
            self::clearWithoutEvidence(),
        ];
    }

    /**
     * @return array<int, Action>
     */
    public static function bankFileRemoveActions(): array
    {
        return [
            self::ignore(),
            self::delete(),
        ];
    }

    /**
     * @return array<int, Action>
     */
    public static function operationsRemoveActions(): array
    {
        return [
            self::deletePendingOperational(),
        ];
    }

    public static function openInBankClearingAction(string $queueFilter): Action
    {
        return Action::make('openBankClearingWorkspace')
            ->label(__('Open in bank clearing'))
            ->icon('heroicon-o-arrow-top-right-on-square')
            ->color('primary')
            ->url(BankAccountsResource::listUrl(
                BankClearingTabRegistry::TAB_QUEUE,
                queueFilter: $queueFilter,
            ));
    }

    public static function clearedRecordActions(): array
    {
        return TableRecordActionGroups::wrap([
            self::view(),
            self::unmatchCleared(),
            self::unmatchSmsBankLink(),
        ]);
    }

    public static function unmatchCleared(): Action
    {
        return Action::make('unmatchCleared')
            ->label(fn (BankTransaction $record): string => $record->bank_clearance_match_group_id !== null
                ? __('Unmatch group')
                : __('Unmatch'))
            ->icon('heroicon-o-link-slash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(fn (BankTransaction $record): string => $record->bank_clearance_match_group_id !== null
                ? __('Unmatch clearance group')
                : __('Unmatch bank clearance'))
            ->modalDescription(function (BankTransaction $record): string {
                if ($record->bank_clearance_match_group_id !== null) {
                    $groupCount = BankTransaction::query()
                        ->where('bank_clearance_match_group_id', $record->bank_clearance_match_group_id)
                        ->count();

                    return __('Reverse the entire :count-row match group, restore uncleared status, and reverse master-bank ledger entries.', [
                        'count' => $groupCount,
                    ]);
                }

                return __('Reverse this match, restore uncleared status, and reverse the master-bank ledger entry.');
            })
            ->visible(fn (BankTransaction $record): bool => $record->is_cleared && $record->duplicate_of_id === null)
            ->action(function (BankTransaction $record, BankClearingMatchService $matching, Action $action): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $matching->unmatchClearedRow($record),
                        __('Could not unmatch bank clearance'),
                    )
                ) {
                    return;
                }

                $isGroup = $record->bank_clearance_match_group_id !== null;

                Notification::make()
                    ->title($isGroup
                        ? __('Match group reversed')
                        : __('Bank clearance unmatched'))
                    ->success()
                    ->send();
            });
    }

    public static function view(): Action
    {
        return ViewBankTransactionAction::make()
            ->label(__('View'))
            ->modalContent(fn (BankTransaction $record) => TenantPortalViewModal::content(
                BankClearingQueuePresenter::modalSections($record),
            ));
    }

    public static function postAs(): Action
    {
        return Action::make('postAs')
            ->label(__('Post as…'))
            ->icon('heroicon-o-tag')
            ->color('primary')
            ->modalHeading(__('Post as…'))
            ->modalDescription(__('Record this bank line as an operation and clear it in one step.'))
            ->modalSubmitActionLabel(__('Post'))
            ->modalWidth('md')
            ->visible(fn (BankTransaction $record): bool => BankImportPostAsService::canPostAs($record))
            ->fillForm(fn (BankTransaction $record): array => [
                'type' => (float) $record->amount >= 0
                    ? BankImportPostAsService::TYPE_MEMBER_DEPOSIT
                    : BankImportPostAsService::TYPE_EXPENSE_OUT,
                'description' => FundFlowService::resolveBankLineDetail($record),
                'transaction_date' => $record->transaction_date?->toDateString()
                    ?? (string) $record->transaction_date,
            ])
            ->schema([
                Select::make('type')
                    ->label(__('Type'))
                    ->options(fn (BankTransaction $record): array => BankImportPostAsService::typeOptionsForAmount(
                        (float) $record->amount,
                    ))
                    ->required()
                    ->native(false)
                    ->live(),
                MemberSelect::make('member_id')
                    ->required(fn (Get $get): bool => BankImportPostAsService::requiresMember(
                        (string) ($get('type') ?? ''),
                    ))
                    ->visible(fn (Get $get): bool => BankImportPostAsService::requiresMember(
                        (string) ($get('type') ?? ''),
                    )),
                Textarea::make('description')
                    ->label(__('Description'))
                    ->rows(2)
                    ->required()
                    ->maxLength(500),
                DatePicker::make('transaction_date')
                    ->label(__('Date'))
                    ->native(false)
                    ->required(),
            ])
            ->action(function (
                BankTransaction $record,
                array $data,
                Action $action,
                BankImportPostAsService $service,
            ): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $service->postAs(
                            $record,
                            (string) $data['type'],
                            (string) $data['description'],
                            filled($data['member_id'] ?? null) ? (int) $data['member_id'] : null,
                            filled($data['transaction_date'] ?? null) ? (string) $data['transaction_date'] : null,
                        ),
                        __('Could not post bank line'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Bank line posted'))
                    ->success()
                    ->send();
            });
    }

    public static function autoMatch(): Action
    {
        return Action::make('autoMatch')
            ->label(__('Auto-match'))
            ->icon('heroicon-o-bolt')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('Pair this row with the only bank import line within amount and date tolerance.'))
            ->visible(fn (BankTransaction $record, BankClearingMatchService $matching): bool => $matching->findUniqueCandidate($record) !== null)
            ->action(function (BankTransaction $record, BankClearingMatchService $matching, Action $action): void {
                if (! $matching->autoMatchWhenUnique($record)) {
                    ActionModalFailure::present(
                        $action,
                        __('No unique bank import line is available anymore.'),
                        __('Could not match automatically'),
                    );

                    return;
                }

                Notification::make()->title(__('Matched to bank import line'))->success()->send();
            });
    }

    public static function matchToBankLine(): Action
    {
        return Action::make('matchToBankLine')
            ->label(__('Match'))
            ->icon('heroicon-o-link')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('Match to bank line'))
            ->modalDescription(__('Pair this row with a specific imported bank statement line as evidence.'))
            ->visible(fn (BankTransaction $record, BankClearingQueueService $queue): bool => $queue->isOperationsItem($record))
            ->form([
                Select::make('imported_transaction_id')
                    ->label(__('Bank statement line'))
                    ->options(function (BankTransaction $record, BankClearingMatchService $matching): array {
                        return $matching->findManualImportedCandidates($record)
                            ->mapWithKeys(fn (BankTransaction $txn): array => [
                                $txn->id => $matching->formatMatchOptionLabel($txn),
                            ])
                            ->all();
                    })
                    ->searchable()
                    ->preload()
                    ->required()
                    ->helperText(function (): string {
                        $manualDays = ContributionPolicySettings::bankMatchManualDateRangeDays();
                        $autoDays = ContributionPolicySettings::bankMatchDateRangeDays();

                        if ($manualDays > 0) {
                            return __('CSV lines within ±:manual days and the same amount (Settings → Reconciliation). Auto-match uses ±:auto days.', [
                                'manual' => $manualDays,
                                'auto' => $autoDays,
                            ]);
                        }

                        return __('CSV lines with the same amount are listed (closest dates first). Configure windows in Settings → Reconciliation. Auto-match uses ±:auto days.', [
                            'auto' => $autoDays,
                        ]);
                    }),
            ])
            ->action(function (BankTransaction $record, array $data, Action $action, BankClearingMatchService $matching): void {
                $imported = BankTransaction::findOrFail($data['imported_transaction_id']);

                if (! $matching->isImportedMatchCandidate($imported)) {
                    ActionModalFailure::present(
                        $action,
                        __('Choose a bank import line that is not already linked to a posting.'),
                        __('That statement line cannot be matched'),
                    );

                    return;
                }

                $matching->clearMatchPair($record, $imported);

                Notification::make()->title(__('Matched to bank import line'))->success()->send();
            });
    }

    public static function matchToMultipleBankLines(): Action
    {
        return Action::make('matchToMultipleBankLines')
            ->label(__('Match to multiple'))
            ->icon('heroicon-o-squares-plus')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('Match to multiple bank lines'))
            ->modalDescription(__('Pair this operational row with several imported statement lines whose amounts sum to the same total.'))
            ->visible(fn (BankTransaction $record, BankClearingQueueService $queue): bool => $queue->isOperationsItem($record))
            ->form([
                Placeholder::make('target_amount')
                    ->label(__('Operational amount'))
                    ->content(fn (BankTransaction $record): string => number_format((float) $record->amount, 2, '.', ',')),
                CheckboxList::make('imported_transaction_ids')
                    ->label(__('Bank statement lines'))
                    ->options(function (BankTransaction $record, BankClearingMatchService $matching): array {
                        return $matching->findGroupMatchImportedCandidates($record)
                            ->mapWithKeys(fn (BankTransaction $txn): array => [
                                $txn->id => $matching->formatMatchOptionLabel($txn),
                            ])
                            ->all();
                    })
                    ->live()
                    ->required()
                    ->minItems(2)
                    ->columns(1),
                Placeholder::make('selected_total')
                    ->label(__('Selected total'))
                    ->content(function (BankTransaction $record, Get $get, BankClearingMatchService $matching): HtmlString {
                        $selectedIds = array_map('intval', (array) ($get('imported_transaction_ids') ?? []));
                        $selected = BankTransaction::query()->whereIn('id', $selectedIds)->get();
                        $target = (float) $record->amount;
                        $total = $matching->sumTransactionAmounts($selected);
                        $tolerance = ContributionPolicySettings::reconTolerance();
                        $balanced = abs($total - $target) <= $tolerance;
                        $delta = number_format(abs($total - $target), 2, '.', ',');

                        $message = $balanced
                            ? __('Selected lines balance within tolerance.')
                            : __('Difference: :delta (tolerance :tolerance).', [
                                'delta' => $delta,
                                'tolerance' => number_format($tolerance, 2, '.', ','),
                            ]);

                        return new HtmlString(
                            '<div class="space-y-1">'
                            .'<div><strong>'.e(number_format($total, 2, '.', ',')).'</strong></div>'
                            .'<div class="text-sm '.($balanced ? 'text-success-600 dark:text-success-400' : 'text-warning-600 dark:text-warning-400').'">'
                            .e($message)
                            .'</div></div>'
                        );
                    }),
            ])
            ->action(function (BankTransaction $record, array $data, Action $action, BankClearingMatchService $matching): void {
                $imported = BankTransaction::query()
                    ->whereIn('id', (array) ($data['imported_transaction_ids'] ?? []))
                    ->get();

                if ($imported->count() < 2) {
                    ActionModalFailure::present(
                        $action,
                        __('Select at least two bank import lines.'),
                        __('Could not match group'),
                    );

                    return;
                }

                foreach ($imported as $line) {
                    if (! $matching->isImportedMatchCandidate($line)) {
                        ActionModalFailure::present(
                            $action,
                            __('One or more bank import lines are no longer eligible.'),
                            __('Could not match group'),
                        );

                        return;
                    }
                }

                if (! $matching->groupAmountsMatch(collect([$record]), $imported)) {
                    ActionModalFailure::present(
                        $action,
                        __('Selected amounts do not balance within tolerance.'),
                        __('Could not match group'),
                    );

                    return;
                }

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $matching->clearMatchGroup(collect([$record]), $imported),
                        __('Could not match group'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Matched to :count bank import lines', ['count' => $imported->count()]))
                    ->success()
                    ->send();
            });
    }

    public static function matchToMultipleOperations(): Action
    {
        return Action::make('matchToMultipleOperations')
            ->label(__('Match to multiple'))
            ->icon('heroicon-o-squares-plus')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('Match to multiple operations'))
            ->modalDescription(__('Pair this bank import line with several operational rows whose amounts sum to the same total.'))
            ->visible(fn (BankTransaction $record, BankClearingQueueService $queue, BankClearingMatchService $matching): bool => $queue->isBankFileItem($record)
                && $matching->isImportedMatchCandidate($record))
            ->form([
                Placeholder::make('target_amount')
                    ->label(__('Bank line amount'))
                    ->content(fn (BankTransaction $record): string => number_format((float) $record->amount, 2, '.', ',')),
                CheckboxList::make('operational_transaction_ids')
                    ->label(__('Operational rows'))
                    ->options(function (BankTransaction $record, BankClearingMatchService $matching): array {
                        return $matching->findGroupMatchOperationalCandidates($record)
                            ->mapWithKeys(fn (BankTransaction $txn): array => [
                                $txn->id => $matching->formatMatchOptionLabel($txn),
                            ])
                            ->all();
                    })
                    ->live()
                    ->required()
                    ->minItems(2)
                    ->columns(1),
                Placeholder::make('selected_total')
                    ->label(__('Selected total'))
                    ->content(function (BankTransaction $record, Get $get, BankClearingMatchService $matching): HtmlString {
                        $selectedIds = array_map('intval', (array) ($get('operational_transaction_ids') ?? []));
                        $selected = BankTransaction::query()->whereIn('id', $selectedIds)->get();
                        $target = (float) $record->amount;
                        $total = $matching->sumTransactionAmounts($selected);
                        $tolerance = ContributionPolicySettings::reconTolerance();
                        $balanced = abs($total - $target) <= $tolerance;
                        $delta = number_format(abs($total - $target), 2, '.', ',');

                        $message = $balanced
                            ? __('Selected lines balance within tolerance.')
                            : __('Difference: :delta (tolerance :tolerance).', [
                                'delta' => $delta,
                                'tolerance' => number_format($tolerance, 2, '.', ','),
                            ]);

                        return new HtmlString(
                            '<div class="space-y-1">'
                            .'<div><strong>'.e(number_format($total, 2, '.', ',')).'</strong></div>'
                            .'<div class="text-sm '.($balanced ? 'text-success-600 dark:text-success-400' : 'text-warning-600 dark:text-warning-400').'">'
                            .e($message)
                            .'</div></div>'
                        );
                    }),
            ])
            ->action(function (BankTransaction $record, array $data, Action $action, BankClearingMatchService $matching): void {
                $operational = BankTransaction::query()
                    ->whereIn('id', (array) ($data['operational_transaction_ids'] ?? []))
                    ->get();

                if ($operational->count() < 2) {
                    ActionModalFailure::present(
                        $action,
                        __('Select at least two operational rows.'),
                        __('Could not match group'),
                    );

                    return;
                }

                foreach ($operational as $line) {
                    if (! $matching->isPendingClearance($line)) {
                        ActionModalFailure::present(
                            $action,
                            __('One or more operational rows are no longer eligible.'),
                            __('Could not match group'),
                        );

                        return;
                    }
                }

                if (! $matching->groupAmountsMatch($operational, collect([$record]))) {
                    ActionModalFailure::present(
                        $action,
                        __('Selected amounts do not balance within tolerance.'),
                        __('Could not match group'),
                    );

                    return;
                }

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $matching->clearMatchGroup($operational, collect([$record])),
                        __('Could not match group'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Matched to :count operational rows', ['count' => $operational->count()]))
                    ->success()
                    ->send();
            });
    }

    public static function matchToSms(): Action
    {
        return Action::make('matchToSms')
            ->label(__('Match to SMS'))
            ->icon('heroicon-o-device-phone-mobile')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('Match to SMS row'))
            ->modalDescription(__('Link this bank import line to an SMS transaction as evidence.'))
            ->visible(fn (BankTransaction $record, BankClearingQueueService $queue, SmsBankClearingMatchService $smsMatching): bool => $queue->isBankFileItem($record)
                && $smsMatching->isBankMatchEligible($record))
            ->form([
                Select::make('sms_transaction_id')
                    ->label(__('SMS row'))
                    ->options(function (BankTransaction $record, SmsBankClearingMatchService $smsMatching): array {
                        return $smsMatching->findManualSmsCandidates($record)
                            ->mapWithKeys(fn (SmsTransaction $sms): array => [
                                $sms->id => $smsMatching->formatSmsMatchOptionLabel($sms),
                            ])
                            ->all();
                    })
                    ->searchable()
                    ->preload()
                    ->required(),
            ])
            ->action(function (BankTransaction $record, array $data, Action $action, SmsBankClearingMatchService $smsMatching): void {
                $sms = SmsTransaction::findOrFail($data['sms_transaction_id']);

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $smsMatching->clearMatchPair($sms, $record),
                        __('Could not match to SMS row'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Linked to SMS row'))->success()->send();
            });
    }

    public static function matchToMultipleSms(): Action
    {
        return Action::make('matchToMultipleSms')
            ->label(__('Match to multiple SMS'))
            ->icon('heroicon-o-squares-plus')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('Match to multiple SMS rows'))
            ->modalDescription(__('Link this bank import line to several SMS rows whose amounts sum to the same total.'))
            ->visible(fn (BankTransaction $record, BankClearingQueueService $queue, SmsBankClearingMatchService $smsMatching): bool => $queue->isBankFileItem($record)
                && $smsMatching->isBankMatchEligible($record))
            ->form([
                Placeholder::make('target_amount')
                    ->label(__('Bank line amount'))
                    ->content(fn (BankTransaction $record): string => number_format((float) $record->amount, 2, '.', ',')),
                CheckboxList::make('sms_transaction_ids')
                    ->label(__('SMS rows'))
                    ->options(function (BankTransaction $record, SmsBankClearingMatchService $smsMatching): array {
                        return $smsMatching->findGroupMatchSmsCandidates($record)
                            ->mapWithKeys(fn (SmsTransaction $sms): array => [
                                $sms->id => $smsMatching->formatSmsMatchOptionLabel($sms),
                            ])
                            ->all();
                    })
                    ->live()
                    ->required()
                    ->minItems(2)
                    ->columns(1),
                Placeholder::make('selected_total')
                    ->label(__('Selected total'))
                    ->content(function (BankTransaction $record, Get $get, SmsBankClearingMatchService $smsMatching): HtmlString {
                        $selectedIds = array_map('intval', (array) ($get('sms_transaction_ids') ?? []));
                        $selected = SmsTransaction::query()->whereIn('id', $selectedIds)->get();
                        $target = (float) $record->amount;
                        $total = $smsMatching->sumSmsAmounts($selected);
                        $tolerance = ContributionPolicySettings::reconTolerance();
                        $balanced = abs($total - $target) <= $tolerance;

                        return new HtmlString(
                            '<div class="space-y-1">'
                            .'<div><strong>'.e(number_format($total, 2, '.', ',')).'</strong></div>'
                            .'<div class="text-sm '.($balanced ? 'text-success-600 dark:text-success-400' : 'text-warning-600 dark:text-warning-400').'">'
                            .e($balanced
                                ? __('Selected rows balance within tolerance.')
                                : __('Difference: :delta (tolerance :tolerance).', [
                                    'delta' => number_format(abs($total - $target), 2, '.', ','),
                                    'tolerance' => number_format($tolerance, 2, '.', ','),
                                ]))
                            .'</div></div>'
                        );
                    }),
            ])
            ->action(function (BankTransaction $record, array $data, Action $action, SmsBankClearingMatchService $smsMatching): void {
                $smsRows = SmsTransaction::query()
                    ->whereIn('id', (array) ($data['sms_transaction_ids'] ?? []))
                    ->get();

                if ($smsRows->count() < 2) {
                    ActionModalFailure::present($action, __('Select at least two SMS rows.'), __('Could not match group'));

                    return;
                }

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $smsMatching->clearMatchGroup($smsRows, collect([$record])),
                        __('Could not match group'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Linked to :count SMS rows', ['count' => $smsRows->count()]))
                    ->success()
                    ->send();
            });
    }

    public static function unmatchSmsBankLink(): Action
    {
        return Action::make('unmatchSmsBankLink')
            ->label(fn (BankTransaction $record): string => $record->sms_clearance_match_group_id !== null
                && (SmsTransaction::query()->where('sms_clearance_match_group_id', $record->sms_clearance_match_group_id)->count()
                    + BankTransaction::query()->where('sms_clearance_match_group_id', $record->sms_clearance_match_group_id)->count()) > 2
                ? __('Unmatch SMS group')
                : __('Unmatch SMS link'))
            ->icon('heroicon-o-link-slash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Unmatch SMS ↔ bank link'))
            ->visible(fn (BankTransaction $record): bool => $record->sms_clearance_match_group_id !== null)
            ->action(function (BankTransaction $record, SmsBankClearingMatchService $smsMatching, Action $action): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $smsMatching->unmatchClearedRow($record),
                        __('Could not unmatch SMS link'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('SMS link removed'))->success()->send();
            });
    }

    public static function clearWithoutEvidence(): Action
    {
        return Action::make('clearWithoutEvidence')
            ->label(__('Clear'))
            ->icon('heroicon-o-check')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('Clear without bank evidence'))
            ->modalDescription(__('Mark this operational row cleared without pairing to an imported bank statement line. Use when the bank movement is known but no CSV line is available.'))
            ->visible(fn (BankTransaction $record, BankClearingQueueService $queue): bool => $queue->isOperationsItem($record))
            ->form([
                Textarea::make('note')
                    ->label(__('Reason or reference'))
                    ->rows(3)
                    ->maxLength(500)
                    ->helperText(__('Optional audit note stored on the bank line description.')),
            ])
            ->action(function (BankTransaction $record, array $data, BankClearingMatchService $matching): void {
                $matching->clearWithoutEvidence($record, filled($data['note'] ?? null) ? (string) $data['note'] : null);

                Notification::make()->title(__('Cleared without bank evidence'))->success()->send();
            });
    }

    public static function ignore(): Action
    {
        return Action::make('ignore')
            ->label(__('Ignore'))
            ->icon('heroicon-o-x-mark')
            ->color('gray')
            ->requiresConfirmation()
            ->visible(fn (BankTransaction $record, BankClearingQueueService $queue): bool => $queue->isBankFileItem($record)
                && $record->status === 'imported')
            ->action(function (BankTransaction $record): void {
                $record->update(['status' => 'ignored']);
                Notification::make()->title(__('Transaction ignored'))->send();
            });
    }

    public static function delete(): Action
    {
        return BankTransactionTableActions::delete()
            ->label(__('Delete'))
            ->visible(fn (BankTransaction $record, BankClearingQueueService $queue): bool => $queue->isBankFileItem($record)
                && BankTransactionDeletion::canDelete($record));
    }

    public static function deletePendingOperational(): Action
    {
        return BankTransactionTableActions::deletePendingOperationalClearance()
            ->label(__('Delete'))
            ->visible(fn (BankTransaction $record, BankClearingQueueService $queue): bool => $queue->isOperationsItem($record)
                && PendingOperationalClearanceDeletionService::canDelete($record));
    }

    public static function matchSelectedBulk(): BulkAction
    {
        return BulkAction::make('matchSelected')
            ->label(__('Match'))
            ->icon('heroicon-o-link')
            ->color('primary')
            ->requiresConfirmation()
            ->modalDescription(__('Pair pending operational rows with imported statement lines, match one pending row to several imports, or select at least two rows on each side when totals balance.'))
            ->action(function (Collection $records, BankClearingMatchService $matching): void {
                $stats = $matching->autoMatchSelected($records);

                if ($stats['manual_pair'] && $stats['matched'] === 1) {
                    Notification::make()
                        ->title(__('Matched to bank import line'))
                        ->success()
                        ->send();

                    return;
                }

                self::notifyMatchStats($stats, __('Match finished'));
            });
    }

    public static function matchAllUniqueBulk(): BulkAction
    {
        return BulkAction::make('matchAllUnique')
            ->label(__('Auto-match'))
            ->icon('heroicon-o-bolt')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('Match rows that have exactly one counterpart within tolerance. Ambiguous rows are skipped.'))
            ->action(function (Collection $records, BankClearingMatchService $matching): void {
                $stats = $matching->autoMatchUnique($records);

                self::notifyMatchStats($stats, __('Automatic match finished'));
            });
    }

    public static function clearWithoutEvidenceBulk(): BulkAction
    {
        return BulkAction::make('clearWithoutEvidenceBulk')
            ->label(__('Clear'))
            ->icon('heroicon-o-check')
            ->color('gray')
            ->requiresConfirmation()
            ->modalHeading(__('Clear without bank evidence'))
            ->modalDescription(__('Mark operational rows cleared without pairing to an imported bank statement line. Bank file rows are skipped.'))
            ->form([
                Textarea::make('note')
                    ->label(__('Reason or reference'))
                    ->rows(3)
                    ->maxLength(500)
                    ->helperText(__('Optional audit note appended to each cleared operational row.')),
            ])
            ->action(function (BulkAction $action, Collection $records, array $data, BankClearingMatchService $matching, BankClearingQueueService $queue): void {
                $note = filled($data['note'] ?? null) ? (string) $data['note'] : null;
                $cleared = 0;
                $skipped = 0;

                foreach ($records as $record) {
                    if (! $record instanceof BankTransaction || ! $queue->isOperationsItem($record)) {
                        $skipped++;

                        continue;
                    }

                    try {
                        $matching->clearWithoutEvidence($record, $note);
                        $cleared++;
                    } catch (Throwable $exception) {
                        $label = filled($record->description)
                            ? $record->description
                            : '#'.$record->id;

                        $action->reportBulkProcessingFailure(
                            message: $label.': '.$exception->getMessage(),
                        );
                        $skipped++;
                    }
                }

                if ($cleared === 0 && $skipped > 0) {
                    Notification::make()
                        ->title(__('No operational rows could be cleared'))
                        ->warning()
                        ->send();

                    return;
                }

                Notification::make()
                    ->title(__(':count row(s) cleared without bank evidence', ['count' => $cleared]))
                    ->body($skipped > 0
                        ? __(':count skipped', ['count' => $skipped])
                        : null)
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function ignoreBulk(): BulkAction
    {
        return BulkAction::make('ignoreSelected')
            ->label(__('Ignore'))
            ->icon('heroicon-o-x-mark')
            ->color('gray')
            ->requiresConfirmation()
            ->action(function (Collection $records, BankClearingQueueService $queue): void {
                $count = 0;

                foreach ($records as $record) {
                    if ($queue->isBankFileItem($record) && $record->status === 'imported') {
                        $record->update(['status' => 'ignored']);
                        $count++;
                    }
                }

                Notification::make()->title(__(':count transaction(s) ignored', ['count' => $count]))->send();
            });
    }

    public static function deleteBulk(): DeleteBulkAction
    {
        return DeleteBulkAction::make('deleteQueueRows')
            ->label(__('Delete'))
            ->modalHeading(__('Delete rows'))
            ->modalDescription(__('Removes bank import lines or pending operational matches. Linked postings and accepted operations are reversed first where required.'))
            ->using(function (DeleteBulkAction $action, Collection $records): void {
                $bankDeletion = app(BankTransactionDeletion::class);
                $operationalDeletion = app(PendingOperationalClearanceDeletionService::class);
                $queue = app(BankClearingQueueService::class);
                $removed = 0;

                foreach ($records as $record) {
                    if (! $record instanceof BankTransaction) {
                        continue;
                    }

                    try {
                        if ($queue->isOperationsItem($record)) {
                            if (! PendingOperationalClearanceDeletionService::canDelete($record)) {
                                continue;
                            }

                            $operationalDeletion->delete($record);
                        } elseif ($queue->isBankFileItem($record) && BankTransactionDeletion::canDelete($record)) {
                            $bankDeletion->delete($record);
                        } else {
                            continue;
                        }

                        $removed++;
                    } catch (Throwable $exception) {
                        $label = filled($record->description)
                            ? $record->description
                            : '#'.$record->id;

                        $action->reportBulkProcessingFailure(
                            message: $label.': '.$exception->getMessage(),
                        );
                    }
                }

                if ($removed > 0) {
                    Notification::make()
                        ->title(__(':count row(s) removed', ['count' => $removed]))
                        ->success()
                        ->send();
                }
            });
    }

    /**
     * @param  array{matched: int, ambiguous: int, skipped: int, manual_pair?: bool}  $stats
     */
    private static function notifyMatchStats(array $stats, string $title): void
    {
        if ($stats['matched'] === 0 && $stats['ambiguous'] === 0 && ($stats['skipped'] ?? 0) > 0) {
            Notification::make()
                ->title(__('No lines could be matched'))
                ->body(__('Select uncleared operational rows or bank file lines with a unique counterpart within tolerance.'))
                ->warning()
                ->send();

            return;
        }

        $body = collect([
            $stats['matched'] > 0
            ? __(':count matched', ['count' => $stats['matched']])
            : null,
            ($stats['ambiguous'] ?? 0) > 0
            ? __(':count ambiguous (multiple candidates)', ['count' => $stats['ambiguous']])
            : null,
            ($stats['skipped'] ?? 0) > 0
            ? __(':count skipped', ['count' => $stats['skipped']])
            : null,
        ])->filter()->implode(' · ');

        Notification::make()
            ->title($title)
            ->body($body)
            ->success()
            ->send();
    }
}
