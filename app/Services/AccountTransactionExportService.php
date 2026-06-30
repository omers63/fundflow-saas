<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\Transaction;
use App\Support\BusinessDay;
use App\Support\MasterInvestLedgerImport;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class AccountTransactionExportService
{
    /**
     * @return list<string>
     */
    public static function csvHeaders(): array
    {
        return [
            'id',
            'transacted_at',
            'type',
            'amount',
            'balance_after',
            'description',
            'member_number',
            'reference_type',
            'reference_id',
        ];
    }

    public function downloadCsv(Account $account): StreamedResponse
    {
        if (! $account->is_master) {
            abort(403);
        }

        $filename = 'master-'.$account->type.'-ledger-'.BusinessDay::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($account): void {
            $handle = fopen('php://output', 'w');
            fputcsv($handle, self::csvHeaders());

            $this->query($account)
                ->orderBy('transacted_at')
                ->orderBy('id')
                ->each(function (Transaction $transaction) use ($handle): void {
                    fputcsv($handle, $this->csvRow($transaction));
                });

            fclose($handle);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /**
     * @return Builder<Transaction>
     */
    private function query(Account $account): Builder
    {
        $query = Transaction::query()
            ->where('account_id', $account->id)
            ->with(['member', 'account']);

        if (MasterInvestLedgerImport::isInvestAccount($account)) {
            MasterInvestLedgerImport::applyExportableScope($query);
        }

        return $query;
    }

    /**
     * @return list<int|float|string|null>
     */
    private function csvRow(Transaction $transaction): array
    {
        return [
            $transaction->id,
            $transaction->transacted_at?->toDateTimeString(),
            $transaction->type,
            $transaction->amount,
            $transaction->balance_after,
            $transaction->description,
            $transaction->member?->member_number,
            $transaction->reference_type,
            $transaction->reference_id,
        ];
    }
}
