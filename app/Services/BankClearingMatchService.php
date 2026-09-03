<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankClearanceMatchGroup;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\ExpenseDisbursement;
use App\Models\Tenant\FeeDisbursement;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\InboundPayment;
use App\Models\Tenant\InvestDisbursement;
use App\Models\Tenant\InvestReturn;
use App\Models\Tenant\OutboundPayment;
use App\Models\Tenant\Transaction;
use App\Support\BankStatementBuckets;
use App\Support\BusinessDay;
use App\Support\ContributionPolicySettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Automated bank clearing per fund_management_system_requirements.md §5.7.
 */
class BankClearingMatchService
{
    /**
     * Operational buckets (not real bank CSV imports).
     *
     * @var list<string>
     */
    /**
     * @return list<string>
     */
    public function membershipImportPlaceholderStatementFilenames(): array
    {
        return BankStatementBuckets::MEMBERSHIP_IMPORT_PLACEHOLDERS;
    }

    /**
     * @return list<string>
     */
    public function operationalClearanceStatementFilenames(): array
    {
        return BankStatementBuckets::OPERATIONAL_CLEARANCE;
    }

    /**
     * @param  Builder<BankTransaction>  $query
     * @return Builder<BankTransaction>
     */
    public function applyRealBankStatementLinesScope(Builder $query): Builder
    {
        return $query->whereHas('bankStatement', function (Builder $statementQuery): void {
            $statementQuery->whereNotIn('filename', BankStatementBuckets::SYNTHETIC_OPERATIONAL);
        });
    }

    /**
     * @param  Builder<BankTransaction>  $query
     * @return Builder<BankTransaction>
     */
    public function applyPendingOperationalClearanceScope(Builder $query): Builder
    {
        return $query
            ->uncleared()
            ->where(function (Builder $pendingQuery): void {
                $pendingQuery->whereNotNull('fund_posting_id')
                    ->orWhereNotNull('cash_out_request_id')
                    ->orWhereNotNull('expense_disbursement_id')
                    ->orWhereNotNull('fee_disbursement_id')
                    ->orWhereNotNull('invest_disbursement_id')
                    ->orWhereNotNull('invest_return_id')
                    ->orWhereNotNull('membership_application_id');
            })
            ->whereHas('bankStatement', function (Builder $statementQuery): void {
                $statementQuery->whereIn('filename', BankStatementBuckets::OPERATIONAL_CLEARANCE);
            });
    }

    /**
     * @return list<string>
     */
    public static function masterAccountTypesWithPendingClearance(): array
    {
        return ['cash', 'expense', 'fees', 'invest'];
    }

    public static function masterAccountTypeSupportsPendingClearance(string $type): bool
    {
        return in_array($type, self::masterAccountTypesWithPendingClearance(), true);
    }

    /**
     * @param  Builder<BankTransaction>  $query
     * @return Builder<BankTransaction>
     */
    public function applyPendingOperationalClearanceScopeForMasterAccount(Builder $query, Account $account): Builder
    {
        $scoped = $this->applyPendingOperationalClearanceScope($query);

        return match ($account->type) {
            'cash' => $scoped->where(function (Builder $cashQuery): void {
                $cashQuery->whereNotNull('fund_posting_id')
                    ->orWhereNotNull('cash_out_request_id')
                    ->orWhereNotNull('membership_application_id');
            }),
            'expense' => $scoped->whereNotNull('expense_disbursement_id'),
            'fees' => $scoped->whereNotNull('fee_disbursement_id'),
            'invest' => $scoped->where(function (Builder $investQuery): void {
                $investQuery->whereNotNull('invest_disbursement_id')
                    ->orWhereNotNull('invest_return_id');
            }),
            default => $scoped->whereRaw('0 = 1'),
        };
    }

    public function pendingOperationalClearanceCountForMasterAccount(Account $account): int
    {
        if (! $account->is_master || ! self::masterAccountTypeSupportsPendingClearance($account->type)) {
            return 0;
        }

        return $this->applyPendingOperationalClearanceScopeForMasterAccount(BankTransaction::query(), $account)->count();
    }

    public function pendingOperationalClearanceCount(): int
    {
        return $this->applyPendingOperationalClearanceScope(BankTransaction::query())->count();
    }

    /**
     * Imported CSV lines that still need posting to the master cash pool.
     *
     * @param  Builder<BankTransaction>  $query
     * @return Builder<BankTransaction>
     */
    public function applyBankLinesAwaitingPostingScope(Builder $query): Builder
    {
        return $this->applyRealBankStatementLinesScope($query)
            ->whereIn('status', ['imported', 'mirrored']);
    }

    public function bankLinesAwaitingPostingCount(): int
    {
        return $this->applyBankLinesAwaitingPostingScope(BankTransaction::query())->count();
    }

    public function __construct(
        protected FundPostingService $fundPostings,
        protected MemberCashOutService $cashOuts,
        protected MasterExpenseDisbursementService $expenseDisbursements,
        protected MasterFeeDisbursementService $feeDisbursements,
        protected MasterInvestDisbursementService $investDisbursements,
        protected MasterInvestReturnService $investReturns,
        protected AccountingService $accounting,
        protected FundAuditLogService $audit,
    ) {}

    public function clearanceMatchGroupCount(): int
    {
        return BankClearanceMatchGroup::query()->count();
    }

    /**
     * @return list<string>
     */
    public function syntheticStatementFilenames(): array
    {
        return BankStatementBuckets::SYNTHETIC_OPERATIONAL;
    }

    public function formatMatchOptionLabel(BankTransaction $transaction): string
    {
        $transaction->loadMissing('bankStatement');

        $status = strtoupper($transaction->status);
        $filename = $transaction->bankStatement?->filename;
        $description = trim((string) $transaction->description);

        if ($description === '') {
            $description = '—';
        }

        $parts = [
            Carbon::parse((string) $transaction->transaction_date)->format('Y-m-d'),
            $status,
            number_format((float) $transaction->amount, 2, '.', ','),
            $description,
        ];

        if (filled($filename)) {
            $parts[] = $filename;
        }

        return implode(' | ', $parts);
    }

    /**
     * @return array{matched: int, ambiguous: int, skipped: int, manual_pair: bool}
     */
    public function autoMatchSelected(Collection $records): array
    {
        $stats = [
            'matched' => 0,
            'ambiguous' => 0,
            'skipped' => 0,
            'manual_pair' => false,
        ];

        if ($records->count() === 2) {
            $pair = $this->identifyManualPair($records);

            if ($pair !== null) {
                [$uncleared, $imported] = $pair;

                if ($this->amountsMatch($uncleared, $imported)) {
                    $this->clearMatchPair($uncleared, $imported);
                    $stats['matched'] = 1;
                    $stats['manual_pair'] = true;

                    return $stats;
                }

                $stats['skipped'] = 2;

                return $stats;
            }
        }

        $operational = $records
            ->filter(fn (mixed $record): bool => $record instanceof BankTransaction && $this->isPendingClearance($record))
            ->values();
        $imported = $records
            ->filter(fn (mixed $record): bool => $record instanceof BankTransaction && $this->isImportedMatchCandidate($record))
            ->values();

        if ($operational->count() >= 2 && $imported->count() >= 2 && $this->groupAmountsMatch($operational, $imported)) {
            $this->clearMatchGroup($operational, $imported);
            $stats['matched'] = $operational->count() + $imported->count();
            $stats['manual_pair'] = true;

            return $stats;
        }

        $tolerance = ContributionPolicySettings::reconTolerance();
        $dayRange = ContributionPolicySettings::bankMatchDateRangeDays();

        foreach ($records as $record) {
            if (! $record instanceof BankTransaction) {
                $stats['skipped']++;

                continue;
            }

            if ($this->isPendingClearance($record)) {
                $candidates = $this->findImportedCandidates($record, $tolerance, $dayRange);

                if ($candidates->count() === 1) {
                    $this->clearMatchPair($record, $candidates->first());
                    $stats['matched']++;

                    continue;
                }

                if ($candidates->count() > 1) {
                    $stats['ambiguous']++;

                    continue;
                }

                $stats['skipped']++;

                continue;
            }

            if ($this->isImportedMatchCandidate($record)) {
                $candidates = $this->findUnclearedCandidates($record, $tolerance, $dayRange);

                if ($candidates->count() === 1) {
                    $this->clearMatchPair($candidates->first(), $record);
                    $stats['matched']++;

                    continue;
                }

                if ($candidates->count() > 1) {
                    $stats['ambiguous']++;

                    continue;
                }

                $stats['skipped']++;

                continue;
            }

            $stats['skipped']++;
        }

        return $stats;
    }

    public function findUniqueCandidate(BankTransaction $record): ?BankTransaction
    {
        $tolerance = ContributionPolicySettings::reconTolerance();
        $dayRange = ContributionPolicySettings::bankMatchDateRangeDays();

        if ($this->isPendingClearance($record)) {
            $candidates = $this->findImportedCandidates($record, $tolerance, $dayRange);

            return $candidates->count() === 1 ? $candidates->first() : null;
        }

        if ($this->isImportedMatchCandidate($record)) {
            $candidates = $this->findUnclearedCandidates($record, $tolerance, $dayRange);

            return $candidates->count() === 1 ? $candidates->first() : null;
        }

        return null;
    }

    public function autoMatchWhenUnique(BankTransaction $record): bool
    {
        $candidate = $this->findUniqueCandidate($record);

        if ($candidate === null) {
            return false;
        }

        if ($this->isPendingClearance($record)) {
            $this->clearMatchPair($record, $candidate);

            return true;
        }

        if ($this->isImportedMatchCandidate($record)) {
            $this->clearMatchPair($candidate, $record);

            return true;
        }

        return false;
    }

    /**
     * @return array{matched: int, ambiguous: int, skipped: int}
     */
    public function autoMatchUnique(Collection $records): array
    {
        $stats = [
            'matched' => 0,
            'ambiguous' => 0,
            'skipped' => 0,
        ];

        $tolerance = ContributionPolicySettings::reconTolerance();
        $dayRange = ContributionPolicySettings::bankMatchDateRangeDays();

        foreach ($records as $record) {
            if (! $record instanceof BankTransaction) {
                $stats['skipped']++;

                continue;
            }

            if ($this->isPendingClearance($record)) {
                $candidates = $this->findImportedCandidates($record, $tolerance, $dayRange);

                if ($candidates->count() === 1) {
                    $this->clearMatchPair($record, $candidates->first());
                    $stats['matched']++;

                    continue;
                }

                if ($candidates->count() > 1) {
                    $stats['ambiguous']++;

                    continue;
                }

                $stats['skipped']++;

                continue;
            }

            if ($this->isImportedMatchCandidate($record)) {
                $candidates = $this->findUnclearedCandidates($record, $tolerance, $dayRange);

                if ($candidates->count() === 1) {
                    $this->clearMatchPair($candidates->first(), $record);
                    $stats['matched']++;

                    continue;
                }

                if ($candidates->count() > 1) {
                    $stats['ambiguous']++;

                    continue;
                }

                $stats['skipped']++;

                continue;
            }

            $stats['skipped']++;
        }

        return $stats;
    }

    public function clearWithoutEvidence(BankTransaction $uncleared, ?string $note = null): void
    {
        if (! $this->isPendingClearance($uncleared)) {
            throw new InvalidArgumentException(__('The pending transaction is not eligible for clearance.'));
        }

        if (! $this->isSyntheticOperationalStatement($uncleared)) {
            throw new InvalidArgumentException(__('Only operational pending rows can be cleared without a bank import line.'));
        }

        app(BankTransactionClearanceService::class)->markClearedWithoutEvidence($uncleared, $note);
    }

    public function clearMatchPair(BankTransaction $uncleared, BankTransaction $imported): void
    {
        if (! $this->isPendingClearance($uncleared)) {
            throw new InvalidArgumentException(__('The pending transaction is not eligible for clearance.'));
        }

        if (! $this->isImportedMatchCandidate($imported)) {
            throw new InvalidArgumentException(__('The imported statement line is not eligible for matching.'));
        }

        DB::transaction(function () use ($uncleared, $imported): void {
            $this->clearMatchPairWithoutAudit($uncleared, $imported);

            $this->audit->log('BANK_MATCH_LINKED', 'bank_clearing', $imported->fresh(), $imported->fresh()->member, [
                'operational_bank_transaction_id' => $uncleared->fresh()->id,
                'imported_bank_transaction_id' => $imported->fresh()->id,
            ]);
        });
    }

    private function clearMatchPairWithoutAudit(BankTransaction $uncleared, BankTransaction $imported): void
    {
        $skipMasterBankLedger = $uncleared->expense_disbursement_id !== null
            || $uncleared->fee_disbursement_id !== null
            || $uncleared->invest_disbursement_id !== null
            || $uncleared->invest_return_id !== null;

        if ($uncleared->cash_out_request_id) {
            $this->cashOuts->clearTransaction($uncleared, $imported);
        } elseif ($uncleared->fee_disbursement_id) {
            $this->feeDisbursements->clearTransaction($uncleared, $imported);
        } elseif ($uncleared->expense_disbursement_id) {
            $this->expenseDisbursements->clearTransaction($uncleared, $imported);
        } elseif ($uncleared->invest_return_id) {
            $this->investReturns->clearTransaction($uncleared, $imported);
        } elseif ($uncleared->invest_disbursement_id) {
            $this->investDisbursements->clearTransaction($uncleared, $imported);
        } else {
            $this->fundPostings->clearTransaction($uncleared, $imported);
        }

        if (! $skipMasterBankLedger) {
            $this->postMatchedImportToMasterBankLedger($imported->fresh());
        }
    }

    /**
     * Clear a 1:N, N:1, or N:M match group.
     *
     * @param  Collection<int, BankTransaction>  $operational
     * @param  Collection<int, BankTransaction>  $imported
     */
    public function clearMatchGroup(Collection $operational, Collection $imported): void
    {
        $operational = $operational->values();
        $imported = $imported->values();

        if ($operational->isEmpty() || $imported->isEmpty()) {
            throw new InvalidArgumentException(__('Select at least one operational row and one bank import line.'));
        }

        $shape = match (true) {
            $operational->count() === 1 && $imported->count() === 1 => null,
            $operational->count() === 1 && $imported->count() >= 2 => 'one_to_many',
            $imported->count() === 1 && $operational->count() >= 2 => 'many_to_one',
            $operational->count() >= 2 && $imported->count() >= 2 => 'many_to_many',
            default => null,
        };

        if ($shape === null) {
            throw new InvalidArgumentException(__('Group match requires two or more rows on at least one side, or use Match for a single pair.'));
        }

        foreach ($operational as $row) {
            if (! $row instanceof BankTransaction || ! $this->isPendingClearance($row)) {
                throw new InvalidArgumentException(__('One or more operational rows are not eligible for clearance.'));
            }
        }

        foreach ($imported as $row) {
            if (! $row instanceof BankTransaction || ! $this->isImportedMatchCandidate($row)) {
                throw new InvalidArgumentException(__('One or more bank import lines are not eligible for matching.'));
            }
        }

        if (! $this->groupAmountsMatch($operational, $imported)) {
            throw new InvalidArgumentException(__('Selected amounts do not balance within tolerance.'));
        }

        DB::transaction(function () use ($operational, $imported, $shape): void {
            $clearedAt = BusinessDay::now();
            $group = BankClearanceMatchGroup::query()->create(['cleared_at' => $clearedAt]);

            if ($shape === 'many_to_many') {
                $this->clearManyToManyMatchGroup($operational, $imported, $group, $clearedAt);

                $this->audit->log('BANK_MATCH_GROUP_LINKED', 'bank_clearing', $group, $imported->first()?->member, [
                    'bank_clearance_match_group_id' => $group->id,
                    'operational_bank_transaction_ids' => $operational->pluck('id')->all(),
                    'imported_bank_transaction_ids' => $imported->pluck('id')->all(),
                    'direction' => 'many_to_many',
                ]);

                return;
            }

            $clearance = app(BankTransactionClearanceService::class);
            $oneOperational = $shape === 'one_to_many';

            if ($oneOperational) {
                $uncleared = $operational->first();
                $firstImported = $imported->first();
                $skipMasterBankLedger = $this->shouldSkipMasterBankLedgerForOperational($uncleared);

                $this->clearMatchPairWithoutAudit($uncleared, $firstImported);

                $uncleared->refresh()->update(['bank_clearance_match_group_id' => $group->id]);
                $anchorImported = $firstImported->fresh();
                $anchorImported->update(['bank_clearance_match_group_id' => $group->id]);

                foreach ($imported->slice(1) as $additionalImported) {
                    $clearance->markImportedClearedInGroup(
                        $additionalImported,
                        $anchorImported,
                        $group->id,
                        $clearedAt,
                    );

                    if (! $skipMasterBankLedger) {
                        $this->postMatchedImportToMasterBankLedger($additionalImported->fresh());
                    }
                }

                $this->audit->log('BANK_MATCH_GROUP_LINKED', 'bank_clearing', $group, $anchorImported?->member, [
                    'bank_clearance_match_group_id' => $group->id,
                    'operational_bank_transaction_ids' => $operational->pluck('id')->all(),
                    'imported_bank_transaction_ids' => $imported->pluck('id')->all(),
                    'direction' => 'one_to_many',
                ]);

                return;
            }

            $importedLine = $imported->first();
            $firstOperational = $operational->first();

            $this->clearMatchPairWithoutAudit($firstOperational, $importedLine);

            $importedLine->refresh()->update(['bank_clearance_match_group_id' => $group->id]);
            $firstOperational->refresh()->update(['bank_clearance_match_group_id' => $group->id]);

            foreach ($operational->slice(1) as $additionalOperational) {
                $clearance->markOperationalClearedInGroup(
                    $additionalOperational,
                    $group->id,
                    $clearedAt,
                );
            }

            $this->audit->log('BANK_MATCH_GROUP_LINKED', 'bank_clearing', $group, $importedLine?->member, [
                'bank_clearance_match_group_id' => $group->id,
                'operational_bank_transaction_ids' => $operational->pluck('id')->all(),
                'imported_bank_transaction_ids' => $imported->pluck('id')->all(),
                'direction' => 'many_to_one',
            ]);
        });
    }

    /**
     * Undo clearance from any cleared row (imported line, operational line, or group member).
     */
    public function unmatchClearedRow(BankTransaction $record): void
    {
        if (! $record->is_cleared) {
            throw new InvalidArgumentException(__('This bank line is not cleared.'));
        }

        if ($record->bank_clearance_match_group_id !== null) {
            $this->unmatchClearedGroup($record);

            return;
        }

        if ($this->isSyntheticOperationalStatement($record)) {
            $imported = $this->findClearedImportedPartner($record);

            if ($imported === null) {
                $record->update([
                    'is_cleared' => false,
                    'cleared_at' => null,
                ]);

                return;
            }

            $this->unmatchClearedPair($imported);

            return;
        }

        $this->unmatchClearedPair($record);
    }

    /**
     * Undo a clearance pair: reverse the master-bank ledger line and return both rows to uncleared.
     */
    public function unmatchClearedPair(BankTransaction $imported): void
    {
        if ($imported->bank_clearance_match_group_id !== null) {
            $this->unmatchClearedGroup($imported);

            return;
        }

        if (! $imported->is_cleared) {
            throw new InvalidArgumentException(__('This bank line is not cleared.'));
        }

        DB::transaction(function () use ($imported): void {
            $partner = $this->clearedPartnerFor($imported);

            if ($imported->master_bank_transaction_id !== null) {
                $ledger = Transaction::query()->find($imported->master_bank_transaction_id);

                if ($ledger !== null && ! $this->accounting->hasExistingReversal($ledger)) {
                    AccountingService::withoutMemberCashCollection(
                        fn () => $this->accounting->createReversalEntry(
                            $ledger,
                            __('Unmatch bank clearance'),
                        ),
                    );
                }
            }

            $imported->update([
                'is_cleared' => false,
                'cleared_at' => null,
                'bank_clearance_match_group_id' => null,
                'fund_posting_id' => null,
                'cash_out_request_id' => null,
                'membership_application_id' => null,
                'expense_disbursement_id' => null,
                'fee_disbursement_id' => null,
                'invest_disbursement_id' => null,
                'invest_return_id' => null,
                'master_bank_transaction_id' => null,
                'status' => 'imported',
            ]);

            if ($partner !== null) {
                $partner->update([
                    'is_cleared' => false,
                    'cleared_at' => null,
                    'bank_clearance_match_group_id' => null,
                ]);
            }

            if ($partner !== null && $this->isSyntheticOperationalStatement($partner)) {
                $this->reconcileSourceBankTransactionLinksAfterUnmatch(
                    collect([$partner]),
                    collect([$imported]),
                );
            }

            $this->audit->log('BANK_MATCH_UNMATCHED', 'bank_clearing', $imported->fresh(), $imported->fresh()->member, [
                'operational_bank_transaction_id' => $partner?->id,
                'imported_bank_transaction_id' => $imported->fresh()->id,
            ]);
        });
    }

    /**
     * Undo an entire N:M clearance group.
     */
    public function unmatchClearedGroup(BankTransaction $anyMember): void
    {
        $groupId = $anyMember->bank_clearance_match_group_id;

        if ($groupId === null) {
            $this->unmatchClearedPair($anyMember);

            return;
        }

        $members = BankTransaction::query()
            ->where('bank_clearance_match_group_id', $groupId)
            ->get();

        if ($members->isEmpty()) {
            throw new InvalidArgumentException(__('This match group could not be found.'));
        }

        DB::transaction(function () use ($members, $groupId): void {
            $this->audit->log('BANK_MATCH_UNMATCHED', 'bank_clearing', BankClearanceMatchGroup::query()->find($groupId), null, [
                'bank_clearance_match_group_id' => $groupId,
                'bank_transaction_ids' => $members->pluck('id')->all(),
            ]);

            $importedLines = $members->filter(
                fn (BankTransaction $row): bool => ! $this->isSyntheticOperationalStatement($row),
            );
            $operationalLines = $members->filter(
                fn (BankTransaction $row): bool => $this->isSyntheticOperationalStatement($row),
            );

            foreach ($importedLines as $imported) {
                if ($imported->master_bank_transaction_id !== null) {
                    $ledger = Transaction::query()->find($imported->master_bank_transaction_id);

                    if ($ledger !== null && ! $this->accounting->hasExistingReversal($ledger)) {
                        AccountingService::withoutMemberCashCollection(
                            fn () => $this->accounting->createReversalEntry(
                                $ledger,
                                __('Unmatch bank clearance'),
                            ),
                        );
                    }
                }

                $imported->update([
                    'is_cleared' => false,
                    'cleared_at' => null,
                    'bank_clearance_match_group_id' => null,
                    'fund_posting_id' => null,
                    'cash_out_request_id' => null,
                    'membership_application_id' => null,
                    'expense_disbursement_id' => null,
                    'fee_disbursement_id' => null,
                    'invest_disbursement_id' => null,
                    'invest_return_id' => null,
                    'master_bank_transaction_id' => null,
                    'status' => 'imported',
                ]);
            }

            foreach ($operationalLines as $operational) {
                $operational->update([
                    'is_cleared' => false,
                    'cleared_at' => null,
                    'bank_clearance_match_group_id' => null,
                ]);
            }

            $this->reconcileSourceBankTransactionLinksAfterUnmatch($operationalLines, $importedLines);
        });
    }

    public function findClearedImportedPartner(BankTransaction $operational): ?BankTransaction
    {
        if (! $this->isSyntheticOperationalStatement($operational)) {
            return null;
        }

        return BankTransaction::query()
            ->with('bankStatement')
            ->where('id', '!=', $operational->id)
            ->where('is_cleared', true)
            ->where(function (Builder $query) use ($operational): void {
                foreach ([
                    'fund_posting_id',
                    'cash_out_request_id',
                    'membership_application_id',
                    'expense_disbursement_id',
                    'fee_disbursement_id',
                    'invest_disbursement_id',
                    'invest_return_id',
                ] as $column) {
                    $value = $operational->getAttribute($column);

                    if ($value !== null) {
                        $query->orWhere($column, $value);
                    }
                }
            })
            ->get()
            ->first(fn (BankTransaction $candidate): bool => ! $this->isSyntheticOperationalStatement($candidate));
    }

    /**
     * @param  Collection<int, BankTransaction>  $operationalLines
     * @param  Collection<int, BankTransaction>  $importedLines
     */
    private function reconcileSourceBankTransactionLinksAfterUnmatch(
        Collection $operationalLines,
        Collection $importedLines,
    ): void {
        if ($importedLines->isEmpty()) {
            return;
        }

        $importedIds = $importedLines->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();

        $sourceLinkColumns = [
            'fund_posting_id' => FundPosting::class,
            'cash_out_request_id' => CashOutRequest::class,
            'expense_disbursement_id' => ExpenseDisbursement::class,
            'fee_disbursement_id' => FeeDisbursement::class,
            'invest_disbursement_id' => InvestDisbursement::class,
            'invest_return_id' => InvestReturn::class,
        ];

        foreach ($operationalLines as $operational) {
            foreach ($sourceLinkColumns as $column => $modelClass) {
                $sourceId = $operational->getAttribute($column);

                if ($sourceId === null) {
                    continue;
                }

                /** @var FundPosting|CashOutRequest|ExpenseDisbursement|FeeDisbursement|InvestDisbursement|InvestReturn|null $source */
                $source = $modelClass::query()->find($sourceId);

                if ($source === null || ! isset($source->bank_transaction_id)) {
                    continue;
                }

                if (in_array((int) $source->bank_transaction_id, $importedIds, true)) {
                    $source->update(['bank_transaction_id' => $operational->id]);
                }
            }
        }

        foreach ([InboundPayment::class, OutboundPayment::class] as $paymentModel) {
            $paymentModel::query()
                ->whereIn('bank_transaction_id', $importedIds)
                ->get()
                ->each(function (InboundPayment|OutboundPayment $payment) use ($operationalLines, $sourceLinkColumns): void {
                    foreach ($operationalLines as $operational) {
                        foreach ($sourceLinkColumns as $column => $modelClass) {
                            $sourceId = $operational->getAttribute($column);

                            if ($sourceId === null) {
                                continue;
                            }

                            if (
                                $payment->source_type === (new $modelClass)->getMorphClass()
                                && (int) $payment->source_id === (int) $sourceId
                            ) {
                                $payment->update(['bank_transaction_id' => $operational->id]);

                                return;
                            }
                        }
                    }
                });
        }
    }

    private function clearedPartnerFor(BankTransaction $imported): ?BankTransaction
    {
        return BankTransaction::query()
            ->where('id', '!=', $imported->id)
            ->where('is_cleared', true)
            ->where(function (Builder $query) use ($imported): void {
                if ($imported->fund_posting_id !== null) {
                    $query->orWhere('fund_posting_id', $imported->fund_posting_id);
                }

                if ($imported->cash_out_request_id !== null) {
                    $query->orWhere('cash_out_request_id', $imported->cash_out_request_id);
                }

                if ($imported->membership_application_id !== null) {
                    $query->orWhere('membership_application_id', $imported->membership_application_id);
                }

                if ($imported->expense_disbursement_id !== null) {
                    $query->orWhere('expense_disbursement_id', $imported->expense_disbursement_id);
                }

                if ($imported->fee_disbursement_id !== null) {
                    $query->orWhere('fee_disbursement_id', $imported->fee_disbursement_id);
                }

                if ($imported->invest_disbursement_id !== null) {
                    $query->orWhere('invest_disbursement_id', $imported->invest_disbursement_id);
                }

                if ($imported->invest_return_id !== null) {
                    $query->orWhere('invest_return_id', $imported->invest_return_id);
                }

                if ($imported->cleared_at !== null) {
                    $query->orWhere('cleared_at', $imported->cleared_at);
                }
            })
            ->orderByRaw('CASE WHEN fund_posting_id IS NOT NULL OR cash_out_request_id IS NOT NULL OR expense_disbursement_id IS NOT NULL OR fee_disbursement_id IS NOT NULL OR invest_disbursement_id IS NOT NULL OR invest_return_id IS NOT NULL THEN 0 ELSE 1 END')
            ->first();
    }

    /**
     * Record the real bank statement line on the master bank ledger.
     * Member/master cash were already posted when the deposit or cash-out was recorded; expense disbursements debit master expense only.
     */
    public function postMatchedImportToMasterBankLedger(BankTransaction $imported): void
    {
        if ($imported->master_bank_transaction_id !== null) {
            return;
        }

        $masterBank = Account::masterBank();

        if ($masterBank === null) {
            return;
        }

        $amount = (float) $imported->amount;

        if (abs($amount) <= 0.00001) {
            return;
        }

        $description = FundFlowService::mirrorToCashLedgerDescription($imported);
        $memberId = $imported->member_id;
        $transactedAt = app(FundFlowService::class)->ledgerDateFromBankLine($imported);

        $ledger = $amount >= 0
            ? $this->accounting->credit($masterBank, $amount, $description, $imported, $transactedAt, $memberId)
            : $this->accounting->debit($masterBank, abs($amount), $description, $imported, $transactedAt, $memberId);

        $imported->forceFill(['master_bank_transaction_id' => $ledger->id])->saveQuietly();
    }

    public function isPendingClearance(BankTransaction $transaction): bool
    {
        if ($transaction->is_cleared) {
            return false;
        }

        return $transaction->fund_posting_id !== null
            || $transaction->cash_out_request_id !== null
            || $transaction->expense_disbursement_id !== null
            || $transaction->fee_disbursement_id !== null
            || $transaction->invest_disbursement_id !== null
            || $transaction->invest_return_id !== null
            || $transaction->membership_application_id !== null;
    }

    /**
     * A real bank statement line that can be paired with an uncleared posting.
     */
    public function isImportedMatchCandidate(BankTransaction $transaction): bool
    {
        if ($transaction->duplicate_of_id !== null) {
            return false;
        }

        if ($transaction->is_cleared) {
            return false;
        }

        if (
            $transaction->fund_posting_id !== null
            || $transaction->membership_application_id !== null
            || $transaction->cash_out_request_id !== null
            || $transaction->expense_disbursement_id !== null
            || $transaction->fee_disbursement_id !== null
            || $transaction->invest_disbursement_id !== null
            || $transaction->invest_return_id !== null
        ) {
            return false;
        }

        if ($this->isSyntheticOperationalStatement($transaction)) {
            return false;
        }

        return in_array($transaction->status, ['imported', 'mirrored', 'posted'], true);
    }

    public function isSyntheticOperationalStatement(BankTransaction $transaction): bool
    {
        $filename = $transaction->bankStatement?->filename;

        return $filename !== null && in_array($filename, BankStatementBuckets::SYNTHETIC_OPERATIONAL, true);
    }

    /**
     * @return array{matched: int, ambiguous: int, unmatched: int}
     */
    public function autoMatchImportedLines(?Collection $importedLines = null): array
    {
        $lines = $importedLines ?? $this->bankStatementMatchTargetQuery()->get();

        $stats = ['matched' => 0, 'ambiguous' => 0, 'unmatched' => 0];
        $tolerance = ContributionPolicySettings::reconTolerance();
        $dayRange = ContributionPolicySettings::bankMatchDateRangeDays();

        foreach ($lines as $imported) {
            $candidates = $this->findUnclearedCandidates($imported, $tolerance, $dayRange);

            if ($candidates->count() === 1) {
                $this->clearMatchPair($candidates->first(), $imported);
                $stats['matched']++;

                continue;
            }

            if ($candidates->count() > 1) {
                $stats['ambiguous']++;

                continue;
            }

            $stats['unmatched']++;
        }

        return $stats;
    }

    /**
     * @return array{
     *     ambiguous: list<array{imported_bank_transaction_id: int, candidate_ids: list<int>}>,
     *     unmatched_imported: list<int>
     * }
     */
    public function scanMatchExceptions(): array
    {
        $lines = $this->bankStatementMatchTargetQuery()->get();

        $tolerance = ContributionPolicySettings::reconTolerance();
        $dayRange = ContributionPolicySettings::bankMatchDateRangeDays();

        $ambiguous = [];
        $unmatchedImported = [];

        foreach ($lines as $imported) {
            $candidates = $this->findUnclearedCandidates($imported, $tolerance, $dayRange);

            if ($candidates->count() === 1) {
                continue;
            }

            if ($candidates->count() > 1) {
                $ambiguous[] = [
                    'imported_bank_transaction_id' => $imported->id,
                    'candidate_ids' => $candidates->pluck('id')->all(),
                ];

                continue;
            }

            $unmatchedImported[] = $imported->id;
        }

        return [
            'ambiguous' => $ambiguous,
            'unmatched_imported' => $unmatchedImported,
        ];
    }

    /**
     * Detect 1→N / N→1 sum hints for manual group matching (pairs and triples only).
     *
     * @return array{
     *     one_to_many: list<array{uncleared_bank_transaction_id: int, imported_bank_transaction_ids: list<int>}>,
     *     many_to_one: list<array{imported_bank_transaction_id: int, uncleared_bank_transaction_ids: list<int>}>,
     *     many_to_many: list<array{uncleared_bank_transaction_ids: list<int>, imported_bank_transaction_ids: list<int>}>,
     * }
     */
    public function scanGroupMatchHints(): array
    {
        $tolerance = ContributionPolicySettings::reconTolerance();
        $oneToMany = [];
        $manyToOne = [];
        $manyToMany = [];
        $importedHintIds = [];
        $operationalHintIds = [];

        $operationalRows = BankTransaction::query()
            ->uncleared()
            ->where(function ($query): void {
                $query->whereNotNull('fund_posting_id')
                    ->orWhereNotNull('cash_out_request_id')
                    ->orWhereNotNull('expense_disbursement_id')
                    ->orWhereNotNull('fee_disbursement_id')
                    ->orWhereNotNull('invest_disbursement_id')
                    ->orWhereNotNull('invest_return_id')
                    ->orWhereNotNull('membership_application_id');
            })
            ->whereHas('bankStatement', function (Builder $statementQuery): void {
                $statementQuery->whereIn('filename', BankStatementBuckets::OPERATIONAL_CLEARANCE);
            })
            ->get();

        foreach ($operationalRows as $operational) {
            if ($this->findImportedCandidates($operational, $tolerance)->count() === 1) {
                continue;
            }

            $candidates = $this->findGroupMatchImportedCandidates($operational);

            if ($candidates->count() < 2) {
                continue;
            }

            $subset = $this->findBalancingSubset($candidates, (float) $operational->amount, $tolerance);

            if ($subset === null) {
                continue;
            }

            $oneToMany[] = [
                'uncleared_bank_transaction_id' => $operational->id,
                'imported_bank_transaction_ids' => $subset,
            ];

            $operationalHintIds[(int) $operational->id] = true;

            foreach ($subset as $importedId) {
                $importedHintIds[(int) $importedId] = true;
            }
        }

        $importedLines = $this->bankStatementMatchTargetQuery()->get();

        foreach ($importedLines as $imported) {
            if ($this->findUnclearedCandidates($imported, $tolerance)->count() === 1) {
                continue;
            }

            $candidates = $this->findGroupMatchOperationalCandidates($imported);

            if ($candidates->count() < 2) {
                continue;
            }

            $subset = $this->findBalancingSubset($candidates, (float) $imported->amount, $tolerance);

            if ($subset === null) {
                continue;
            }

            $manyToOne[] = [
                'imported_bank_transaction_id' => $imported->id,
                'uncleared_bank_transaction_ids' => $subset,
            ];

            $importedHintIds[(int) $imported->id] = true;

            foreach ($subset as $operationalId) {
                $operationalHintIds[(int) $operationalId] = true;
            }
        }

        $operationalItems = $operationalRows
            ->reject(fn (BankTransaction $row): bool => isset($operationalHintIds[(int) $row->id]))
            ->map(fn (BankTransaction $transaction): array => [
                'id' => $transaction->id,
                'amount' => (float) $transaction->amount,
            ])
            ->values()
            ->all();

        $importedItems = $importedLines
            ->reject(fn (BankTransaction $row): bool => isset($importedHintIds[(int) $row->id]))
            ->map(fn (BankTransaction $transaction): array => [
                'id' => $transaction->id,
                'amount' => (float) $transaction->amount,
            ])
            ->values()
            ->all();

        foreach ($this->findManyToManySubsetHints($operationalItems, $importedItems, $tolerance) as $hint) {
            $manyToMany[] = $hint;

            foreach ($hint['uncleared_bank_transaction_ids'] as $operationalId) {
                $operationalHintIds[(int) $operationalId] = true;
            }

            foreach ($hint['imported_bank_transaction_ids'] as $importedId) {
                $importedHintIds[(int) $importedId] = true;
            }
        }

        return [
            'one_to_many' => $oneToMany,
            'many_to_one' => $manyToOne,
            'many_to_many' => $manyToMany,
            'hint_imported_ids' => array_keys($importedHintIds),
        ];
    }

    /**
     * @param  Collection<int, BankTransaction>  $operational
     * @param  Collection<int, BankTransaction>  $imported
     */
    private function clearManyToManyMatchGroup(
        Collection $operational,
        Collection $imported,
        BankClearanceMatchGroup $group,
        CarbonInterface $clearedAt,
    ): void {
        $clearance = app(BankTransactionClearanceService::class);
        $pairCount = min($operational->count(), $imported->count());

        for ($index = 0; $index < $pairCount; $index++) {
            $ops = $operational[$index];
            $import = $imported[$index];

            $this->clearMatchPairWithoutAudit($ops, $import);

            $ops->fresh()->update(['bank_clearance_match_group_id' => $group->id]);
            $import->fresh()->update(['bank_clearance_match_group_id' => $group->id]);
        }

        foreach ($operational->slice($pairCount) as $extraOperational) {
            $clearance->markOperationalClearedInGroup($extraOperational, $group->id, $clearedAt);
        }

        $anchorImported = $imported->first()?->fresh();
        $skipMasterBankLedger = $operational->first() !== null
            && $this->shouldSkipMasterBankLedgerForOperational($operational->first());

        foreach ($imported->slice($pairCount) as $extraImported) {
            if ($anchorImported !== null) {
                $clearance->markImportedClearedInGroup(
                    $extraImported,
                    $anchorImported,
                    $group->id,
                    $clearedAt,
                );
            }

            if (! $skipMasterBankLedger) {
                $this->postMatchedImportToMasterBankLedger($extraImported->fresh());
            }
        }
    }

    /**
     * @param  list<array{id: int, amount: float}>  $leftItems
     * @param  list<array{id: int, amount: float}>  $rightItems
     * @return list<array{uncleared_bank_transaction_ids: list<int>, imported_bank_transaction_ids: list<int>}>
     */
    private function findManyToManySubsetHints(array $leftItems, array $rightItems, float $tolerance): array
    {
        $hints = [];
        $leftCount = count($leftItems);

        for ($leftA = 0; $leftA < $leftCount; $leftA++) {
            for ($leftB = $leftA + 1; $leftB < $leftCount; $leftB++) {
                $leftSum = $leftItems[$leftA]['amount'] + $leftItems[$leftB]['amount'];
                $leftIds = [(int) $leftItems[$leftA]['id'], (int) $leftItems[$leftB]['id']];
                $rightCount = count($rightItems);

                for ($rightA = 0; $rightA < $rightCount; $rightA++) {
                    for ($rightB = $rightA + 1; $rightB < $rightCount; $rightB++) {
                        $rightSum = $rightItems[$rightA]['amount'] + $rightItems[$rightB]['amount'];

                        if (abs($leftSum - $rightSum) > $tolerance) {
                            continue;
                        }

                        $hints[] = [
                            'uncleared_bank_transaction_ids' => $leftIds,
                            'imported_bank_transaction_ids' => [
                                (int) $rightItems[$rightA]['id'],
                                (int) $rightItems[$rightB]['id'],
                            ],
                        ];
                    }
                }
            }
        }

        return $hints;
    }

    /**
     * @param  EloquentCollection<int, BankTransaction>  $candidates
     * @return list<int>|null
     */
    private function findBalancingSubset(
        EloquentCollection $candidates,
        float $target,
        float $tolerance,
        int $maxCandidates = 15,
    ): ?array {
        $items = $candidates
            ->take($maxCandidates)
            ->values()
            ->map(fn (BankTransaction $transaction): array => [
                'id' => $transaction->id,
                'amount' => (float) $transaction->amount,
            ])
            ->all();

        $count = count($items);

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                $sum = $items[$i]['amount'] + $items[$j]['amount'];

                if (abs($sum - $target) <= $tolerance) {
                    return [(int) $items[$i]['id'], (int) $items[$j]['id']];
                }
            }
        }

        for ($i = 0; $i < $count; $i++) {
            for ($j = $i + 1; $j < $count; $j++) {
                for ($k = $j + 1; $k < $count; $k++) {
                    $sum = $items[$i]['amount'] + $items[$j]['amount'] + $items[$k]['amount'];

                    if (abs($sum - $target) <= $tolerance) {
                        return [
                            (int) $items[$i]['id'],
                            (int) $items[$j]['id'],
                            (int) $items[$k]['id'],
                        ];
                    }
                }
            }
        }

        return null;
    }

    /**
     * @return EloquentCollection<int, BankTransaction>
     */
    public function findUnclearedCandidates(
        BankTransaction $imported,
        ?float $tolerance = null,
        ?int $dayRange = null,
    ): EloquentCollection {
        $tolerance ??= ContributionPolicySettings::reconTolerance();
        $dayRange ??= ContributionPolicySettings::bankMatchDateRangeDays();
        $amount = (float) $imported->amount;
        $date = $imported->transaction_date;

        return BankTransaction::query()
            ->uncleared()
            ->where(function ($query): void {
                $query->whereNotNull('fund_posting_id')
                    ->orWhereNotNull('cash_out_request_id')
                    ->orWhereNotNull('expense_disbursement_id')
                    ->orWhereNotNull('fee_disbursement_id')
                    ->orWhereNotNull('invest_disbursement_id')
                    ->orWhereNotNull('invest_return_id')
                    ->orWhereNotNull('membership_application_id');
            })
            ->whereDoesntHave('bankStatement', function ($query): void {
                $query->whereIn('filename', BankStatementBuckets::MEMBERSHIP_IMPORT_PLACEHOLDERS);
            })
            ->whereBetween('amount', [$amount - $tolerance, $amount + $tolerance])
            ->when($date, function ($query) use ($date, $dayRange): void {
                $parsedDate = Carbon::parse((string) $date);
                $query->whereBetween('transaction_date', [
                    $parsedDate->copy()->subDays($dayRange)->toDateString(),
                    $parsedDate->copy()->addDays($dayRange)->toDateString(),
                ]);
            })
            ->get();
    }

    /**
     * @return EloquentCollection<int, BankTransaction>
     */
    public function findImportedCandidates(
        BankTransaction $uncleared,
        ?float $tolerance = null,
        ?int $dayRange = null,
    ): EloquentCollection {
        $tolerance ??= ContributionPolicySettings::reconTolerance();
        $dayRange ??= ContributionPolicySettings::bankMatchDateRangeDays();
        $amount = (float) $uncleared->amount;
        $date = $uncleared->transaction_date;

        return $this->bankStatementMatchTargetQuery()
            ->whereBetween('amount', [$amount - $tolerance, $amount + $tolerance])
            ->when($date, function ($query) use ($date, $dayRange): void {
                $parsedDate = Carbon::parse((string) $date);
                $query->whereBetween('transaction_date', [
                    $parsedDate->copy()->subDays($dayRange)->toDateString(),
                    $parsedDate->copy()->addDays($dayRange)->toDateString(),
                ]);
            })
            ->get();
    }

    /**
     * Manual Match picker candidates.
     *
     * Amount tolerance always applies. Date window comes from
     * {@see ContributionPolicySettings::bankMatchManualDateRangeDays()} (0 = amount only).
     * Results are sorted by closeness to the operational date.
     *
     * @return EloquentCollection<int, BankTransaction>
     */
    public function findManualImportedCandidates(
        BankTransaction $uncleared,
        ?float $tolerance = null,
        ?int $dayRange = null,
    ): EloquentCollection {
        $tolerance ??= ContributionPolicySettings::reconTolerance();
        $dayRange ??= ContributionPolicySettings::bankMatchManualDateRangeDays();
        $amount = (float) $uncleared->amount;
        $anchor = $uncleared->transaction_date
            ? Carbon::parse((string) $uncleared->transaction_date)->startOfDay()
            : null;

        $candidates = $this->bankStatementMatchTargetQuery()
            ->whereBetween('amount', [$amount - $tolerance, $amount + $tolerance])
            ->when(
                $dayRange > 0 && $anchor !== null,
                function ($query) use ($anchor, $dayRange): void {
                    $query->whereBetween('transaction_date', [
                        $anchor->copy()->subDays($dayRange)->toDateString(),
                        $anchor->copy()->addDays($dayRange)->toDateString(),
                    ]);
                },
            )
            ->get();

        if ($anchor === null) {
            return $candidates;
        }

        return $candidates
            ->sortBy(function (BankTransaction $candidate) use ($anchor): int {
                $candidateDate = $candidate->transaction_date
                    ? Carbon::parse((string) $candidate->transaction_date)->startOfDay()
                    : $anchor;

                return (int) abs($anchor->diffInDays($candidateDate));
            })
            ->values();
    }

    /**
     * Imported bank lines eligible for a 1→N group match (no single-line amount filter).
     *
     * @return EloquentCollection<int, BankTransaction>
     */
    public function findGroupMatchImportedCandidates(
        BankTransaction $uncleared,
        ?int $dayRange = null,
    ): EloquentCollection {
        $dayRange ??= ContributionPolicySettings::bankMatchManualDateRangeDays();
        $anchor = $uncleared->transaction_date
            ? Carbon::parse((string) $uncleared->transaction_date)->startOfDay()
            : null;

        $candidates = $this->bankStatementMatchTargetQuery()
            ->when(
                $dayRange > 0 && $anchor !== null,
                function ($query) use ($anchor, $dayRange): void {
                    $query->whereBetween('transaction_date', [
                        $anchor->copy()->subDays($dayRange)->toDateString(),
                        $anchor->copy()->addDays($dayRange)->toDateString(),
                    ]);
                },
            )
            ->get();

        if ($anchor === null) {
            return $candidates;
        }

        return $candidates
            ->sortBy(function (BankTransaction $candidate) use ($anchor): int {
                $candidateDate = $candidate->transaction_date
                    ? Carbon::parse((string) $candidate->transaction_date)->startOfDay()
                    : $anchor;

                return (int) abs($anchor->diffInDays($candidateDate));
            })
            ->values();
    }

    /**
     * Operational rows eligible for an N→1 group match (no single-line amount filter).
     *
     * @return EloquentCollection<int, BankTransaction>
     */
    public function findGroupMatchOperationalCandidates(
        BankTransaction $imported,
        ?int $dayRange = null,
    ): EloquentCollection {
        $dayRange ??= ContributionPolicySettings::bankMatchManualDateRangeDays();
        $anchor = $imported->transaction_date
            ? Carbon::parse((string) $imported->transaction_date)->startOfDay()
            : null;

        $candidates = BankTransaction::query()
            ->uncleared()
            ->where(function ($query): void {
                $query->whereNotNull('fund_posting_id')
                    ->orWhereNotNull('cash_out_request_id')
                    ->orWhereNotNull('expense_disbursement_id')
                    ->orWhereNotNull('fee_disbursement_id')
                    ->orWhereNotNull('invest_disbursement_id')
                    ->orWhereNotNull('invest_return_id')
                    ->orWhereNotNull('membership_application_id');
            })
            ->whereDoesntHave('bankStatement', function ($query): void {
                $query->whereIn('filename', BankStatementBuckets::MEMBERSHIP_IMPORT_PLACEHOLDERS);
            })
            ->whereHas('bankStatement', function (Builder $statementQuery): void {
                $statementQuery->whereIn('filename', BankStatementBuckets::OPERATIONAL_CLEARANCE);
            })
            ->when(
                $dayRange > 0 && $anchor !== null,
                function ($query) use ($anchor, $dayRange): void {
                    $query->whereBetween('transaction_date', [
                        $anchor->copy()->subDays($dayRange)->toDateString(),
                        $anchor->copy()->addDays($dayRange)->toDateString(),
                    ]);
                },
            )
            ->get();

        if ($anchor === null) {
            return $candidates;
        }

        return $candidates
            ->sortBy(function (BankTransaction $candidate) use ($anchor): int {
                $candidateDate = $candidate->transaction_date
                    ? Carbon::parse((string) $candidate->transaction_date)->startOfDay()
                    : $anchor;

                return (int) abs($anchor->diffInDays($candidateDate));
            })
            ->values();
    }

    /**
     * @param  Collection<int, BankTransaction>  $operational
     * @param  Collection<int, BankTransaction>  $imported
     */
    public function groupAmountsMatch(Collection $operational, Collection $imported, ?float $tolerance = null): bool
    {
        $tolerance ??= ContributionPolicySettings::reconTolerance();

        return abs($this->sumTransactionAmounts($operational) - $this->sumTransactionAmounts($imported)) <= $tolerance;
    }

    /**
     * @param  Collection<int, BankTransaction>  $transactions
     */
    public function sumTransactionAmounts(Collection $transactions): float
    {
        return (float) $transactions->sum(fn (BankTransaction $transaction): float => (float) $transaction->amount);
    }

    protected function shouldSkipMasterBankLedgerForOperational(BankTransaction $uncleared): bool
    {
        return $uncleared->expense_disbursement_id !== null
            || $uncleared->fee_disbursement_id !== null
            || $uncleared->invest_disbursement_id !== null
            || $uncleared->invest_return_id !== null;
    }

    /**
     * @return Builder<BankTransaction>
     */
    protected function bankStatementMatchTargetQuery(): Builder
    {
        return BankTransaction::query()
            ->with('bankStatement')
            ->uncleared()
            ->whereIn('status', ['imported', 'mirrored', 'posted'])
            ->whereNull('fund_posting_id')
            ->whereNull('membership_application_id')
            ->whereNull('cash_out_request_id')
            ->whereNull('expense_disbursement_id')
            ->whereNull('fee_disbursement_id')
            ->whereNull('invest_disbursement_id')
            ->whereNull('invest_return_id')
            ->whereNull('duplicate_of_id')
            ->where(function (Builder $query): void {
                // Import → mirror → post to member completes without an uncleared posting to match.
                $query->where('status', '!=', 'posted')
                    ->orWhereNull('member_id');
            })
            ->whereHas('bankStatement', function ($query): void {
                $query->whereNotIn('filename', BankStatementBuckets::SYNTHETIC_OPERATIONAL);
            });
    }

    /**
     * @param  Collection<int, BankTransaction>  $records
     * @return array{0: BankTransaction, 1: BankTransaction}|null
     */
    protected function identifyManualPair(Collection $records): ?array
    {
        $uncleared = $records->first(fn (BankTransaction $record): bool => $this->isPendingClearance($record));
        $imported = $records->first(fn (BankTransaction $record): bool => $this->isImportedMatchCandidate($record));

        if ($uncleared === null || $imported === null) {
            return null;
        }

        return [$uncleared, $imported];
    }

    protected function amountsMatch(
        BankTransaction $uncleared,
        BankTransaction $imported,
        ?float $tolerance = null,
    ): bool {
        $tolerance ??= ContributionPolicySettings::reconTolerance();

        return abs((float) $uncleared->amount - (float) $imported->amount) <= $tolerance;
    }
}
