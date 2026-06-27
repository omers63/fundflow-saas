<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Account;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\Transaction;
use App\Services\AccountingService;
use App\Services\ContributionService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Converts idle "Legacy migration cash" supplement credits into merged contribution fund posts.
 */
final class LegacyMigrationCashSupplementRepairService
{
    private const LEGACY_CASH_PREFIX = 'Legacy migration cash —';

    public function __construct(
        private readonly AccountingService $accounting,
        private readonly LegacyPaymentImportService $paymentImport,
    ) {
    }

    /**
     * @return array{repaired: int, skipped: int, errors: list<string>}
     */
    public function repairAll(): array
    {
        @set_time_limit(0);

        $result = [
            'repaired' => 0,
            'skipped' => 0,
            'errors' => [],
        ];

        $transactions = Transaction::query()
            ->where('type', 'credit')
            ->where('description', 'like', self::LEGACY_CASH_PREFIX . '%')
            ->whereHas('account', fn($query) => $query->where('type', 'cash')->where('is_master', false))
            ->orderBy('transacted_at')
            ->orderBy('id')
            ->get();

        ContributionService::withoutPostedNotifications(function () use ($transactions, &$result): void {
            ContributionService::withoutLiveCollectionGuards(function () use ($transactions, &$result): void {
                AccountingService::withoutMemberCashCollection(function () use ($transactions, &$result): void {
                    foreach ($transactions as $transaction) {
                        try {
                            if ($this->repairTransaction($transaction)) {
                                $result['repaired']++;
                            } else {
                                $result['skipped']++;
                            }
                        } catch (\Throwable $exception) {
                            $result['errors'][] = "Transaction {$transaction->id}: {$exception->getMessage()}";
                        }
                    }
                });
            });
        });

        $this->accounting->rebuildAllLedgerAccountBalancesFromTransactionLines(reconcileLineBalances: false);

        return $result;
    }

    private function repairTransaction(Transaction $transaction): bool
    {
        $transaction->loadMissing('account.member');

        $member = $transaction->account?->member;

        if ($member === null) {
            throw new InvalidArgumentException(__('Transaction :id is not linked to a member cash account.', [
                'id' => $transaction->id,
            ]));
        }

        $amount = (float) $transaction->amount;

        if ($amount <= 0.00001) {
            return false;
        }

        [$month, $year] = $this->resolvePeriodFromLegacyCashDescription((string) $transaction->description);

        $contribution = Contribution::findForMemberPeriod($member->id, $month, $year);

        if ($contribution === null) {
            throw new InvalidArgumentException(__('No contribution found for member :member period :period.', [
                'member' => $member->member_number,
                'period' => sprintf('%04d-%02d', $year, $month),
            ]));
        }

        $postedAt = Carbon::parse((string) ($transaction->transacted_at ?? $contribution->posted_at ?? $contribution->period));
        $notes = trim((string) $transaction->description);

        DB::transaction(function () use ($member, $month, $year, $amount, $postedAt, $notes, $transaction, $contribution): void {
            $this->paymentImport->mergeLegacyContributionTopUp(
                $member,
                $month,
                $year,
                $amount,
                $postedAt,
                $notes,
                creditCashFirst: false,
            );

            $periodLabel = Carbon::create($year, $month, 1)->format('M Y');
            $description = __('Contribution — :period', ['period' => $periodLabel]);

            Transaction::query()
                ->whereKey($transaction->id)
                ->update([
                    'description' => $description,
                    'reference_type' => Contribution::class,
                    'reference_id' => $contribution->id,
                ]);

            $masterCash = Account::masterCash();

            if ($masterCash !== null) {
                Transaction::query()
                    ->where('account_id', $masterCash->id)
                    ->where('type', 'credit')
                    ->where('transacted_at', $transaction->transacted_at)
                    ->where('amount', $transaction->amount)
                    ->where('description', 'like', '%' . self::LEGACY_CASH_PREFIX . '%')
                    ->when(
                        $transaction->member_id !== null,
                        fn($query) => $query->where('member_id', $transaction->member_id),
                    )
                    ->whereNull('reference_type')
                    ->limit(1)
                    ->update([
                        'description' => $description,
                        'reference_type' => Contribution::class,
                        'reference_id' => $contribution->id,
                    ]);
            }
        });

        return true;
    }

    /**
     * @return array{0: int, 1: int}
     */
    public function resolvePeriodFromLegacyCashDescription(string $description): array
    {
        if (preg_match('/\[legacy-import:([^\]]+)\]/', $description, $matches) === 1) {
            $parts = explode('|', $matches[1]);

            foreach ($parts as $part) {
                if (preg_match('/^\d{4}-\d{2}$/', trim($part)) === 1) {
                    $date = Carbon::parse(trim($part) . '-01');

                    return [(int) $date->month, (int) $date->year];
                }
            }
        }

        if (preg_match('/' . preg_quote(self::LEGACY_CASH_PREFIX, '/') . '\s*([A-Za-z]{3}\s+\d{4})/', $description, $matches) === 1) {
            $date = Carbon::parse($matches[1]);

            return [(int) $date->month, (int) $date->year];
        }

        throw new InvalidArgumentException(__('Could not resolve contribution period from legacy cash description.'));
    }
}
