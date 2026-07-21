<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Filament\Tenant\Resources\BankAccounts\BankAccountsResource;
use App\Filament\Tenant\Support\BankClearingTabRegistry;
use App\Filament\Tenant\Support\TenantPortalViewModal;
use App\Filament\Tenant\Support\ViewBankTransactionAction;
use App\Models\Tenant\BankTransaction;
use App\Services\BankClearingMatchService;
use App\Services\BankClearingQueueService;
use App\Services\FundFlowService;
use App\Services\PendingOperationalClearanceDeletionService;
use App\Support\BankClearing\BankClearingQueuePresenter;
use App\Support\BankTransactionDeletion;
use App\Support\BankTransactionWorkflow;
use App\Support\ContributionPolicySettings;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
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
        }

        if ($includeOperations) {
            if (! $includeBankFile) {
                $actions[] = self::autoMatch();
            }

            $actions[] = self::matchToBankLine();
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

        $actions = [
            self::matchAllUniqueBulk(),
            self::matchSelectedBulk(),
        ];

        if (in_array($filter, [BankClearingTabRegistry::FILTER_ALL, BankClearingTabRegistry::FILTER_OPERATIONS], true)) {
            $actions[] = self::clearWithoutEvidenceBulk();
        }

        if (in_array($filter, [BankClearingTabRegistry::FILTER_ALL, BankClearingTabRegistry::FILTER_BANK_FILE], true)) {
            $actions[] = self::postToCashBulk();
            $actions[] = self::postToMemberBulk();
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
            self::postToCash(),
            self::postToMember(),
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

    public static function view(): Action
    {
        return ViewBankTransactionAction::make()
            ->label(__('View'))
            ->modalContent(fn (BankTransaction $record) => TenantPortalViewModal::content(
                BankClearingQueuePresenter::modalSections($record),
            ));
    }

    public static function postToCash(): Action
    {
        return Action::make('mirrorToCash')
            ->label(__('Post cash'))
            ->icon('heroicon-o-arrow-right')
            ->color('info')
            ->requiresConfirmation()
            ->modalDescription(__('Post this statement line to the master cash pool.'))
            ->visible(fn (BankTransaction $record): bool => BankTransactionWorkflow::canPostToCash($record))
            ->action(function (BankTransaction $record, FundFlowService $service): void {
                $service->mirrorToCash([$record->id]);
                Notification::make()->title(__('Posted to master cash'))->success()->send();
            });
    }

    public static function postToMember(): Action
    {
        return BankTransactionTableActions::postToMember()
            ->label(__('Post member'));
    }

    public static function postToMemberBulk(): BulkAction
    {
        return BankTransactionTableActions::postToMemberBulk()
            ->label(__('Post member'));
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
            ->modalDescription(__('Pair pending operational rows with imported statement lines, or choose one pending and one imported row to match directly.'))
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

    public static function postToCashBulk(): BulkAction
    {
        return BulkAction::make('mirrorSelectedToCash')
            ->label(__('Post cash'))
            ->icon('heroicon-o-arrow-right')
            ->color('info')
            ->requiresConfirmation()
            ->modalDescription(__('Post imported statement lines to the master cash pool.'))
            ->action(function (Collection $records, FundFlowService $service): void {
                $importedIds = $records
                    ->filter(fn (BankTransaction $record): bool => BankTransactionWorkflow::canPostToCash($record))
                    ->pluck('id');

                if ($importedIds->isEmpty()) {
                    Notification::make()->title(__('No imported lines can be posted to cash'))->warning()->send();

                    return;
                }

                $count = $service->mirrorToCash($importedIds);
                Notification::make()->title(__(':count transaction(s) posted to master cash', ['count' => $count]))->success()->send();
            });
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
