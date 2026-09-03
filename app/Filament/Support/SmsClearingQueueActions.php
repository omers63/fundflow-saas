<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Support\TenantPortalViewModal;
use App\Filament\Tenant\Support\ViewSmsTransactionAction;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Member;
use App\Models\Tenant\SmsTransaction;
use App\Services\AccountingService;
use App\Services\BankClearingMatchService;
use App\Services\SmsBankClearingMatchService;
use App\Services\SmsOperationalClearingMatchService;
use App\Support\ContributionPolicySettings;
use App\Support\EvidenceChannelSettings;
use App\Support\SmsClearing\SmsClearingQueuePresenter;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Utilities\Get;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\HtmlString;

final class SmsClearingQueueActions
{
    /**
     * @return array<int, ActionGroup>
     */
    public static function groupedRecordActions(): array
    {
        return [
            ActionGroup::make(self::resolveActions())
                ->label(__('Resolve'))
                ->icon('heroicon-o-check-circle'),
            ActionGroup::make([
                self::view(),
            ])
                ->label(__('Review'))
                ->icon('heroicon-o-eye'),
            ActionGroup::make(self::removeActions())
                ->label(__('Remove'))
                ->icon('heroicon-o-trash'),
        ];
    }

    /**
     * @return array<int, Action>
     */
    public static function resolveActions(): array
    {
        $actions = [
            self::postToCash(),
            self::autoPost(),
        ];

        if (EvidenceChannelSettings::usesBankCsv()) {
            $actions[] = self::matchToBankLine();
            $actions[] = self::matchToMultipleBankLines();
        }

        if (EvidenceChannelSettings::usesSms()) {
            $actions[] = self::matchToOperationalRow();
        }

        return $actions;
    }

    /**
     * @return array<int, Action>
     */
    public static function opsLinkActions(): array
    {
        return [
            self::matchToOperationalRow(),
            self::unmatchOpsLink(),
        ];
    }

    /**
     * @return array<int, Action>
     */
    public static function bankLinkActions(): array
    {
        if (! EvidenceChannelSettings::usesBankCsv()) {
            return [];
        }

        return [
            self::matchToBankLine(),
            self::matchToMultipleBankLines(),
            self::unmatchBankLink(),
        ];
    }

    /**
     * @return array<int, Action>
     */
    public static function removeActions(): array
    {
        return [
            self::delete(),
        ];
    }

    public static function view(): Action
    {
        return ViewSmsTransactionAction::make()
            ->modalContent(fn (SmsTransaction $record) => TenantPortalViewModal::content(
                SmsClearingQueuePresenter::modalSections($record),
            ));
    }

    public static function postToCash(): Action
    {
        $memberOptions = self::memberOptions();

        return Action::make('postToCash')
            ->label(__('Post to cash'))
            ->icon('heroicon-o-arrow-right-circle')
            ->color('primary')
            ->visible(fn (SmsTransaction $record): bool => ! $record->isPosted())
            ->fillForm(fn (SmsTransaction $record): array => ['member_id' => $record->member_id])
            ->schema([
                Select::make('member_id')
                    ->label(__('Post for member'))
                    ->options($memberOptions)
                    ->searchable()
                    ->required()
                    ->helperText(__('Auto-matched from SMS template, or select manually.')),
            ])
            ->action(function (SmsTransaction $record, array $data): void {
                $member = Member::query()->findOrFail($data['member_id']);
                app(AccountingService::class)->postSmsTransactionToCash($record, $member);

                Notification::make()
                    ->title(__('Posted to cash account'))
                    ->body(__('SMS transaction posted for :name.', ['name' => $member->name]))
                    ->success()
                    ->send();
            });
    }

    public static function autoPost(): Action
    {
        return Action::make('autoPost')
            ->label(__('Post matched member'))
            ->icon('heroicon-o-bolt')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('Posts this row using the auto-matched member.'))
            ->visible(fn (SmsTransaction $record): bool => ! $record->isPosted() && $record->member_id !== null)
            ->action(function (SmsTransaction $record): void {
                $member = Member::query()->findOrFail($record->member_id);
                app(AccountingService::class)->postSmsTransactionToCash($record, $member);

                Notification::make()
                    ->title(__('Posted to cash account'))
                    ->body(__('SMS transaction posted for :name.', ['name' => $member->name]))
                    ->success()
                    ->send();
            });
    }

    public static function delete(): DeleteAction
    {
        return DeleteAction::make()
            ->modalDescription(__('Soft-deletes this SMS import row. If it was posted to cash, matching ledger lines are reversed first.'))
            ->using(function (SmsTransaction $record): bool {
                app(AccountingService::class)->safeDeleteSmsTransaction($record);

                return true;
            });
    }

    public static function bulkAutoPost(): BulkAction
    {
        return BulkAction::make('bulkAutoPost')
            ->label(__('Auto-post matched transactions'))
            ->icon('heroicon-o-bolt')
            ->color('success')
            ->requiresConfirmation()
            ->modalDescription(__('Posts selected rows that already have an auto-matched member. Others are skipped.'))
            ->action(function (Collection $records): void {
                $service = app(AccountingService::class);
                $posted = 0;
                $skipped = 0;

                foreach ($records as $tx) {
                    if ($tx->isPosted() || $tx->member_id === null) {
                        $skipped++;

                        continue;
                    }

                    $member = Member::query()->find($tx->member_id);

                    if ($member === null) {
                        $skipped++;

                        continue;
                    }

                    $service->postSmsTransactionToCash($tx, $member);
                    $posted++;
                }

                Notification::make()
                    ->title(__('Auto-post complete'))
                    ->body(__('Posted: :posted | Skipped: :skipped', ['posted' => $posted, 'skipped' => $skipped]))
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function bulkPostToCash(): BulkAction
    {
        return BulkAction::make('bulkPostToCash')
            ->label(__('Bulk post to a single member'))
            ->icon('heroicon-o-arrow-right-circle')
            ->color('primary')
            ->schema([
                Select::make('member_id')
                    ->label(__('Post all selected for member'))
                    ->options(self::memberOptions())
                    ->searchable()
                    ->required(),
            ])
            ->action(function (Collection $records, array $data): void {
                $member = Member::query()->findOrFail($data['member_id']);
                $service = app(AccountingService::class);
                $posted = 0;
                $skipped = 0;

                foreach ($records as $tx) {
                    if ($tx->isPosted()) {
                        $skipped++;

                        continue;
                    }

                    $service->postSmsTransactionToCash($tx, $member);
                    $posted++;
                }

                Notification::make()
                    ->title(__('Bulk post complete'))
                    ->body(__('Posted: :posted | Already posted (skipped): :skipped', ['posted' => $posted, 'skipped' => $skipped]))
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function bulkMatchManyToManyBank(): BulkAction
    {
        return BulkAction::make('bulkMatchManyToManyBank')
            ->label(__('Match to multiple bank lines'))
            ->icon('heroicon-o-squares-plus')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('Match SMS rows to bank lines'))
            ->modalDescription(__('Select at least two bank import lines whose total matches the sum of the selected SMS rows.'))
            ->visible(fn (Collection $records): bool => $records->count() >= 2)
            ->form([
                Placeholder::make('selected_sms_total')
                    ->label(__('Selected SMS total'))
                    ->content(function (Collection $records, SmsBankClearingMatchService $matching): string {
                        return number_format($matching->sumSmsAmounts($records), 2, '.', ',');
                    }),
                CheckboxList::make('bank_transaction_ids')
                    ->label(__('Bank statement lines'))
                    ->options(function (SmsBankClearingMatchService $matching): array {
                        $bankMatching = app(BankClearingMatchService::class);

                        return $bankMatching
                            ->applyRealBankStatementLinesScope(BankTransaction::query())
                            ->whereNull('sms_clearance_match_group_id')
                            ->whereNull('duplicate_of_id')
                            ->orderByDesc('transaction_date')
                            ->limit(200)
                            ->get()
                            ->mapWithKeys(fn (BankTransaction $txn): array => [
                                $txn->id => $bankMatching->formatMatchOptionLabel($txn),
                            ])
                            ->all();
                    })
                    ->live()
                    ->required()
                    ->minItems(2)
                    ->columns(1),
                Placeholder::make('selected_total')
                    ->label(__('Selected bank total'))
                    ->content(function (Get $get, SmsBankClearingMatchService $matching): HtmlString {
                        $selectedIds = array_map('intval', (array) ($get('bank_transaction_ids') ?? []));
                        $selected = BankTransaction::query()->whereIn('id', $selectedIds)->get();
                        $total = $matching->sumBankAmounts($selected);
                        $tolerance = ContributionPolicySettings::reconTolerance();

                        return new HtmlString(
                            '<div class="space-y-1">'
                            .'<div><strong>'.e(number_format($total, 2, '.', ',')).'</strong></div>'
                            .'<div class="text-sm text-gray-500">'.e(__('Tolerance :tolerance', [
                                'tolerance' => number_format($tolerance, 2, '.', ','),
                            ])).'</div></div>'
                        );
                    }),
            ])
            ->action(function (Collection $records, array $data, BulkAction $action, SmsBankClearingMatchService $matching): void {
                $bankRows = BankTransaction::query()
                    ->whereIn('id', (array) ($data['bank_transaction_ids'] ?? []))
                    ->get();

                if ($records->count() < 2 || $bankRows->count() < 2) {
                    ActionModalFailure::present($action, __('Select at least two SMS rows and two bank import lines.'), __('Could not match group'));

                    return;
                }

                if (! $matching->groupAmountsMatch($records, $bankRows)) {
                    ActionModalFailure::present($action, __('Selected amounts do not balance within tolerance.'), __('Could not match group'));

                    return;
                }

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $matching->clearMatchGroup($records, $bankRows),
                        __('Could not match group'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Linked :sms SMS rows to :bank bank lines', [
                        'sms' => $records->count(),
                        'bank' => $bankRows->count(),
                    ]))
                    ->success()
                    ->send();
            })
            ->deselectRecordsAfterCompletion();
    }

    public static function matchToBankLine(): Action
    {
        return Action::make('matchToBankLine')
            ->label(__('Match to bank'))
            ->icon('heroicon-o-link')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('Match to bank line'))
            ->modalDescription(__('Link this SMS row to a bank CSV import line as evidence. Posting stays separate unless already posted.'))
            ->visible(fn (SmsTransaction $record, SmsBankClearingMatchService $matching): bool => EvidenceChannelSettings::usesBankCsv()
                && $matching->isSmsMatchEligible($record))
            ->form([
                Select::make('bank_transaction_id')
                    ->label(__('Bank statement line'))
                    ->options(function (SmsTransaction $record, SmsBankClearingMatchService $matching): array {
                        return $matching->findManualBankCandidates($record)
                            ->mapWithKeys(fn (BankTransaction $txn): array => [
                                $txn->id => app(BankClearingMatchService::class)->formatMatchOptionLabel($txn),
                            ])
                            ->all();
                    })
                    ->searchable()
                    ->preload()
                    ->required(),
            ])
            ->action(function (SmsTransaction $record, array $data, Action $action, SmsBankClearingMatchService $matching): void {
                $bank = BankTransaction::findOrFail($data['bank_transaction_id']);

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $matching->clearMatchPair($record, $bank),
                        __('Could not match to bank line'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Linked to bank import line'))->success()->send();
            });
    }

    public static function matchToMultipleBankLines(): Action
    {
        return Action::make('matchToMultipleBankLines')
            ->label(__('Match to multiple bank lines'))
            ->icon('heroicon-o-squares-plus')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('Match to multiple bank lines'))
            ->modalDescription(__('Link this SMS row to several bank import lines whose amounts sum to the same total.'))
            ->visible(fn (SmsTransaction $record, SmsBankClearingMatchService $matching): bool => EvidenceChannelSettings::usesBankCsv()
                && $matching->isSmsMatchEligible($record))
            ->form([
                Placeholder::make('target_amount')
                    ->label(__('SMS amount'))
                    ->content(fn (SmsTransaction $record): string => number_format((float) $record->amount, 2, '.', ',')),
                CheckboxList::make('bank_transaction_ids')
                    ->label(__('Bank statement lines'))
                    ->options(function (SmsTransaction $record, SmsBankClearingMatchService $matching): array {
                        $bankMatching = app(BankClearingMatchService::class);

                        return $matching->findGroupMatchBankCandidates($record)
                            ->mapWithKeys(fn (BankTransaction $txn): array => [
                                $txn->id => $bankMatching->formatMatchOptionLabel($txn),
                            ])
                            ->all();
                    })
                    ->live()
                    ->required()
                    ->minItems(2)
                    ->columns(1),
                Placeholder::make('selected_total')
                    ->label(__('Selected total'))
                    ->content(function (SmsTransaction $record, Get $get, SmsBankClearingMatchService $matching): HtmlString {
                        $selectedIds = array_map('intval', (array) ($get('bank_transaction_ids') ?? []));
                        $selected = BankTransaction::query()->whereIn('id', $selectedIds)->get();
                        $target = (float) $record->amount;
                        $total = $matching->sumBankAmounts($selected);
                        $tolerance = ContributionPolicySettings::reconTolerance();
                        $balanced = abs($total - $target) <= $tolerance;

                        return new HtmlString(
                            '<div class="space-y-1">'
                            .'<div><strong>'.e(number_format($total, 2, '.', ',')).'</strong></div>'
                            .'<div class="text-sm '.($balanced ? 'text-success-600 dark:text-success-400' : 'text-warning-600 dark:text-warning-400').'">'
                            .e($balanced
                                ? __('Selected lines balance within tolerance.')
                                : __('Difference: :delta (tolerance :tolerance).', [
                                    'delta' => number_format(abs($total - $target), 2, '.', ','),
                                    'tolerance' => number_format($tolerance, 2, '.', ','),
                                ]))
                            .'</div></div>'
                        );
                    }),
            ])
            ->action(function (SmsTransaction $record, array $data, Action $action, SmsBankClearingMatchService $matching): void {
                $bankRows = BankTransaction::query()
                    ->whereIn('id', (array) ($data['bank_transaction_ids'] ?? []))
                    ->get();

                if ($bankRows->count() < 2) {
                    ActionModalFailure::present($action, __('Select at least two bank import lines.'), __('Could not match group'));

                    return;
                }

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $matching->clearMatchGroup(collect([$record]), $bankRows),
                        __('Could not match group'),
                    )
                ) {
                    return;
                }

                Notification::make()
                    ->title(__('Linked to :count bank import lines', ['count' => $bankRows->count()]))
                    ->success()
                    ->send();
            });
    }

    public static function unmatchBankLink(): Action
    {
        return Action::make('unmatchBankLink')
            ->label(fn (SmsTransaction $record): string => $record->sms_clearance_match_group_id !== null
                && SmsTransaction::query()->where('sms_clearance_match_group_id', $record->sms_clearance_match_group_id)->count() > 1
                ? __('Unmatch bank group')
                : __('Unmatch bank link'))
            ->icon('heroicon-o-link-slash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Unmatch SMS ↔ bank link'))
            ->modalDescription(__('Remove the bank evidence link. Ledger posting is unchanged except master-bank lines created for this link.'))
            ->visible(fn (SmsTransaction $record): bool => $record->is_bank_cleared)
            ->action(function (SmsTransaction $record, SmsBankClearingMatchService $matching, Action $action): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $matching->unmatchClearedRow($record),
                        __('Could not unmatch bank link'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Bank link removed'))->success()->send();
            });
    }

    public static function matchToOperationalRow(): Action
    {
        return Action::make('matchToOperationalRow')
            ->label(__('Match to operational row'))
            ->icon('heroicon-o-link')
            ->color('primary')
            ->requiresConfirmation()
            ->modalHeading(__('Match to operational row'))
            ->modalDescription(__('Clear an uncleared deposit or cash-out operational row using this posted SMS as evidence.'))
            ->visible(fn (SmsTransaction $record, SmsOperationalClearingMatchService $matching): bool => $matching->isSmsOpsMatchEligible($record))
            ->form([
                Select::make('operational_bank_transaction_id')
                    ->label(__('Operational row'))
                    ->options(function (SmsTransaction $record, SmsOperationalClearingMatchService $matching): array {
                        return $matching->findGroupMatchOpsCandidates($record)
                            ->mapWithKeys(fn (BankTransaction $txn): array => [
                                $txn->id => $matching->formatOpsMatchOptionLabel($txn),
                            ])
                            ->all();
                    })
                    ->searchable()
                    ->preload()
                    ->required(),
            ])
            ->action(function (SmsTransaction $record, array $data, Action $action, SmsOperationalClearingMatchService $matching): void {
                $ops = BankTransaction::findOrFail($data['operational_bank_transaction_id']);

                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $matching->clearMatchPair($ops, $record),
                        __('Could not match to operational row'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Linked to operational row'))->success()->send();
            });
    }

    public static function unmatchOpsLink(): Action
    {
        return Action::make('unmatchOpsLink')
            ->label(__('Unmatch ops link'))
            ->icon('heroicon-o-link-slash')
            ->color('danger')
            ->requiresConfirmation()
            ->modalHeading(__('Unmatch SMS ↔ operational link'))
            ->modalDescription(__('Remove the operational clearance link. Ledger posting is unchanged except master-bank lines created for this link.'))
            ->visible(fn (SmsTransaction $record): bool => EvidenceChannelSettings::usesSms() && $record->is_ops_cleared)
            ->action(function (SmsTransaction $record, SmsOperationalClearingMatchService $matching, Action $action): void {
                if (
                    ! ActionModalFailure::attemptThrowable(
                        $action,
                        fn () => $matching->unmatchClearedGroup($record),
                        __('Could not unmatch operational link'),
                    )
                ) {
                    return;
                }

                Notification::make()->title(__('Operational link removed'))->success()->send();
            });
    }

    public static function deleteBulk(): DeleteBulkAction
    {
        return DeleteBulkAction::make()
            ->modalDescription(__('Deletes selected rows; posted transactions are reversed from the ledger first.'))
            ->using(function (DeleteBulkAction $action, Collection $records): void {
                $accounting = app(AccountingService::class);

                foreach ($records as $record) {
                    try {
                        $accounting->safeDeleteSmsTransaction($record);
                    } catch (\Throwable $e) {
                        $action->reportBulkProcessingFailure(message: $e->getMessage());
                        report($e);
                    }
                }
            });
    }

    /**
     * @return array<int|string, string>
     */
    private static function memberOptions(): array
    {
        return Member::query()
            ->orderBy('name')
            ->get()
            ->mapWithKeys(fn (Member $member): array => [
                $member->id => trim(($member->member_number ? $member->member_number.' — ' : '').$member->name),
            ])
            ->all();
    }
}
