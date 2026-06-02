<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Support\BankStatementBuckets;
use App\Support\ContributionPolicySettings;
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
                    ->orWhereNotNull('cash_out_request_id');
            })
            ->whereHas('bankStatement', function (Builder $statementQuery): void {
                $statementQuery->whereIn('filename', BankStatementBuckets::OPERATIONAL_CLEARANCE);
            });
    }

    public function pendingOperationalClearanceCount(): int
    {
        return $this->applyPendingOperationalClearanceScope(BankTransaction::query())->count();
    }

    public function __construct(
        protected FundPostingService $fundPostings,
        protected MemberCashOutService $cashOuts,
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
        $status = strtoupper($transaction->status);

        return sprintf(
            '%s | %s | %s | %s',
            $transaction->transaction_date->format('Y-m-d'),
            $status,
            $transaction->description,
            number_format((float) $transaction->amount, 2, '.', ','),
        );
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

    public function clearMatchPair(BankTransaction $uncleared, BankTransaction $imported): void
    {
        if (! $this->isPendingClearance($uncleared)) {
            throw new InvalidArgumentException(__('The pending transaction is not eligible for clearance.'));
        }

        if (! $this->isImportedMatchCandidate($imported)) {
            throw new InvalidArgumentException(__('The imported statement line is not eligible for matching.'));
        }

        DB::transaction(function () use ($uncleared, $imported): void {
            if ($uncleared->cash_out_request_id) {
                $this->cashOuts->clearTransaction($uncleared, $imported);
            } else {
                $this->fundPostings->clearTransaction($uncleared, $imported);
            }

            $this->postMatchedImportToMasterBankLedger($imported->fresh());
        });
    }

    /**
     * Record the real bank statement line on the master bank ledger.
     * Member/master cash were already posted when the deposit or cash-out was accepted.
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

        $ledger = $amount >= 0
            ? $this->accounting->credit($masterBank, $amount, $description, $imported, null, $memberId)
            : $this->accounting->debit($masterBank, abs($amount), $description, $imported, null, $memberId);

        $imported->forceFill(['master_bank_transaction_id' => $ledger->id])->saveQuietly();
    }

    public function isPendingClearance(BankTransaction $transaction): bool
    {
        if ($transaction->is_cleared) {
            return false;
        }

        return $transaction->fund_posting_id !== null
            || $transaction->cash_out_request_id !== null;
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
                    ->orWhereNotNull('cash_out_request_id');
            })
            ->whereDoesntHave('bankStatement', function ($query): void {
                $query->whereIn('filename', BankStatementBuckets::MEMBERSHIP_IMPORT_PLACEHOLDERS);
            })
            ->when($date, function ($query) use ($date, $dayRange): void {
                $query->whereBetween('transaction_date', [
                    $date->copy()->subDays($dayRange)->toDateString(),
                    $date->copy()->addDays($dayRange)->toDateString(),
                ]);
            })
            ->get()
            ->filter(fn (BankTransaction $candidate): bool => $this->amountsMatch($candidate, $imported, $tolerance))
            ->values();
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
            ->when($date, function ($query) use ($date, $dayRange): void {
                $query->whereBetween('transaction_date', [
                    $date->copy()->subDays($dayRange)->toDateString(),
                    $date->copy()->addDays($dayRange)->toDateString(),
                ]);
            })
            ->get()
            ->filter(fn (BankTransaction $candidate): bool => $this->isImportedMatchCandidate($candidate)
                && $this->amountsMatch($uncleared, $candidate, $tolerance))
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
