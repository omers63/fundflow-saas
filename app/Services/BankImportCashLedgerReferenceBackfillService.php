<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\BankTransaction;
use App\Models\Tenant\Transaction;
use Illuminate\Support\Facades\DB;

/**
 * Attach {@see BankTransaction} as linked source on historical null-reference
 * master/member cash legs created by CSV mirror / post-to-member.
 */
final class BankImportCashLedgerReferenceBackfillService
{
    /**
     * @return array{master_cash: int, member_cash: int}
     */
    public function backfill(): array
    {
        return DB::transaction(function (): array {
            return [
                'master_cash' => $this->backfillMasterCashLegs(),
                'member_cash' => $this->backfillMemberCashLegs(),
            ];
        });
    }

    private function backfillMasterCashLegs(): int
    {
        $updated = 0;
        $morph = (new BankTransaction)->getMorphClass();

        BankTransaction::query()
            ->whereNotNull('master_cash_transaction_id')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$updated, $morph): void {
                foreach ($rows as $bankTxn) {
                    if (! $bankTxn instanceof BankTransaction) {
                        continue;
                    }

                    $cash = Transaction::query()->find($bankTxn->master_cash_transaction_id);

                    if (! $cash instanceof Transaction) {
                        continue;
                    }

                    if ($cash->reference_type !== null && $cash->reference_id !== null) {
                        continue;
                    }

                    $cash->forceFill([
                        'reference_type' => $morph,
                        'reference_id' => $bankTxn->id,
                    ])->saveQuietly();

                    $updated++;
                }
            });

        return $updated;
    }

    private function backfillMemberCashLegs(): int
    {
        $updated = 0;
        $morph = (new BankTransaction)->getMorphClass();

        BankTransaction::query()
            ->where('status', 'posted')
            ->whereNotNull('member_id')
            ->whereNotNull('master_cash_transaction_id')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use (&$updated, $morph): void {
                foreach ($rows as $bankTxn) {
                    if (! $bankTxn instanceof BankTransaction) {
                        continue;
                    }

                    $detail = FundFlowService::resolveBankLineDetail($bankTxn);
                    $amount = abs((float) $bankTxn->amount);
                    $memberId = (int) $bankTxn->member_id;

                    $memberCash = Transaction::query()
                        ->whereNull('reference_type')
                        ->whereNull('reference_id')
                        ->where('member_id', $memberId)
                        ->where('type', (float) $bankTxn->amount >= 0 ? 'credit' : 'debit')
                        ->where('amount', $amount)
                        ->whereHas(
                            'account',
                            fn ($query) => $query
                                ->where('type', 'cash')
                                ->where('is_master', false)
                                ->where('member_id', $memberId),
                        )
                        ->when(
                            $detail !== '',
                            fn ($query) => $query->where('description', 'like', '%'.$detail.'%'),
                        )
                        ->orderBy('id')
                        ->first();

                    if (! $memberCash instanceof Transaction) {
                        continue;
                    }

                    $memberCash->forceFill([
                        'reference_type' => $morph,
                        'reference_id' => $bankTxn->id,
                    ])->saveQuietly();

                    $updated++;
                }
            });

        return $updated;
    }
}
