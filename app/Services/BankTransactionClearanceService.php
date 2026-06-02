<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\BankTransaction;
use Illuminate\Support\Facades\DB;

/**
 * Shared primitives for clearing matched bank transaction pairs.
 *
 * Both fund-postings and cash-out requests clear the same two records:
 * - mark the uncleared line as cleared
 * - mark the imported line as cleared/posted and attach the relevant linkage fields
 */
final class BankTransactionClearanceService
{
    public function clearMatchedPair(
        BankTransaction $uncleared,
        BankTransaction $imported,
        array $importedUpdates,
    ): void {
        DB::transaction(function () use ($uncleared, $imported, $importedUpdates): void {
            $clearedAt = now();

            $uncleared->update([
                'is_cleared' => true,
                'cleared_at' => $clearedAt,
            ]);

            $imported->update(array_merge([
                'is_cleared' => true,
                'cleared_at' => $clearedAt,
            ], $importedUpdates));
        });
    }
}
