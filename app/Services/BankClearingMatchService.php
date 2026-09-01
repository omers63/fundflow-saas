<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankClearanceMatchGroup;
use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Transaction;
use App\Support\BankStatementBuckets;
use App\Support\BusinessDay;
use App\Support\ContributionPolicySettings;
use Carbon\Carbon;
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
    ) {}

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
        });
    }

    /**
     * Clear a 1:N or N:1 match group (one row on one side, multiple on the other).
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

        $oneOperational = $operational->count() === 1 && $imported->count() >= 2;
        $oneImported = $imported->count() === 1 && $operational->count() >= 2;

        if (! $oneOperational && ! $oneImported) {
            throw new InvalidArgumentException(__('Group match requires one row on one side and two or more on the other.'));
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

        DB::transaction(function () use ($operational, $imported, $oneOperational): void {
            $clearedAt = BusinessDay::now();
            $group = BankClearanceMatchGroup::query()->create(['cleared_at' => $clearedAt]);
            $clearance = app(BankTransactionClearanceService::class);

            if ($oneOperational) {
                $uncleared = $operational->first();
                $firstImported = $imported->first();
                $skipMasterBankLedger = $this->shouldSkipMasterBankLedgerForOperational($uncleared);

                $this->clearMatchPair($uncleared, $firstImported);

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

                return;
            }

            $importedLine = $imported->first();
            $firstOperational = $operational->first();

            $this->clearMatchPair($firstOperational, $importedLine);

            $importedLine->refresh()->update(['bank_clearance_match_group_id' => $group->id]);
            $firstOperational->refresh()->update(['bank_clearance_match_group_id' => $group->id]);

            foreach ($operational->slice(1) as $additionalOperational) {
                $clearance->markOperationalClearedInGroup(
                    $additionalOperational,
                    $group->id,
                    $clearedAt,
                );
            }
        });
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

        DB::transaction(function () use ($members): void {
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
        });
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
