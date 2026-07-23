<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Support\BankStatementBuckets;
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
                    ->orWhereNotNull('invest_return_id');
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
                    ->orWhereNotNull('cash_out_request_id');
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
            || $transaction->invest_return_id !== null;
    }

    /**
     * A real bank statement line that can be paired with an uncleared posting.
     */
    public function isImportedMatchCandidate(BankTransaction $transaction): bool
    {
        if ($transaction->duplicate_of_id !== null) {
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
                    ->orWhereNotNull('invest_return_id');
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
     * @return Builder<BankTransaction>
     */
    protected function bankStatementMatchTargetQuery(): Builder
    {
        return BankTransaction::query()
            ->with('bankStatement')
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
