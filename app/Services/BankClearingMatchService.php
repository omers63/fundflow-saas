<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\BankTransaction;
use App\Support\ContributionPolicySettings;
use Illuminate\Support\Collection;

/**
 * Automated bank clearing per fund_management_system_requirements.md §5.7.
 */
class BankClearingMatchService
{
    public function __construct(
        protected FundPostingService $fundPostings,
        protected AccountingService $accounting,
    ) {}

    /**
     * @return array{matched: int, ambiguous: int, unmatched: int}
     */
    public function autoMatchImportedLines(?Collection $importedLines = null): array
    {
        $lines = $importedLines ?? BankTransaction::query()
            ->where('status', 'imported')
            ->where('is_cleared', true)
            ->whereNull('fund_posting_id')
            ->whereNull('membership_application_id')
            ->get();

        $stats = ['matched' => 0, 'ambiguous' => 0, 'unmatched' => 0];
        $tolerance = ContributionPolicySettings::reconTolerance();
        $dayRange = ContributionPolicySettings::bankMatchDateRangeDays();

        foreach ($lines as $imported) {
            $candidates = $this->findUnclearedCandidates($imported, $tolerance, $dayRange);

            if ($candidates->count() === 1) {
                $this->fundPostings->clearTransaction($candidates->first(), $imported);
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
        $lines = BankTransaction::query()
            ->where('status', 'imported')
            ->where('is_cleared', true)
            ->whereNull('fund_posting_id')
            ->whereNull('membership_application_id')
            ->get();

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

    protected function findUnclearedCandidates(
        BankTransaction $imported,
        float $tolerance,
        int $dayRange,
    ): Collection {
        $amount = (float) $imported->amount;
        $date = $imported->transaction_date;

        return BankTransaction::query()
            ->uncleared()
            ->where(function ($query): void {
                $query->whereNotNull('fund_posting_id')
                    ->orWhereNotNull('membership_application_id');
            })
            ->when($date, function ($query) use ($date, $dayRange): void {
                $query->whereBetween('transaction_date', [
                    $date->copy()->subDays($dayRange)->toDateString(),
                    $date->copy()->addDays($dayRange)->toDateString(),
                ]);
            })
            ->get()
            ->filter(fn (BankTransaction $candidate): bool => abs((float) $candidate->amount - $amount) <= $tolerance);
    }

    protected function postBankClearingEntry(BankTransaction $imported): void
    {
        $masterBank = Account::masterBank();
        $masterCash = Account::masterCash();

        if ($masterBank === null || $masterCash === null) {
            return;
        }

        $amount = abs((float) $imported->amount);

        if ($amount <= 0.00001) {
            return;
        }

        $description = __('Bank clearing — import #:id', ['id' => $imported->id]);

        $this->accounting->debit($masterBank, $amount, $description, $imported);
        $this->accounting->credit($masterCash, $amount, $description, $imported);
    }
}
