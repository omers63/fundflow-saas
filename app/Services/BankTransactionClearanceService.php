<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\BankTransaction;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;
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
            $clearedAt = BusinessDay::now();

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

    /**
     * Mark an operational pending row cleared without pairing to an imported bank line.
     */
    public function markClearedWithoutEvidence(BankTransaction $uncleared, ?string $note = null): void
    {
        DB::transaction(function () use ($uncleared, $note): void {
            $updates = [
                'is_cleared' => true,
                'cleared_at' => BusinessDay::now(),
            ];

            if (filled($note)) {
                $updates['description'] = trim(
                    ($uncleared->description ?: __('Operational bank line'))
                    .' — '
                    .__('Cleared without bank evidence: :note', ['note' => $note]),
                );
            }

            $uncleared->update($updates);
        });
    }

    /**
     * Mark an additional imported bank line cleared as part of a 1→N group match.
     */
    public function markImportedClearedInGroup(
        BankTransaction $imported,
        BankTransaction $anchorImported,
        int $groupId,
        CarbonInterface $clearedAt,
    ): void {
        $imported->update([
            'is_cleared' => true,
            'cleared_at' => $clearedAt,
            'bank_clearance_match_group_id' => $groupId,
            'fund_posting_id' => $anchorImported->fund_posting_id,
            'membership_application_id' => $anchorImported->membership_application_id,
            'cash_out_request_id' => $anchorImported->cash_out_request_id,
            'expense_disbursement_id' => $anchorImported->expense_disbursement_id,
            'fee_disbursement_id' => $anchorImported->fee_disbursement_id,
            'invest_disbursement_id' => $anchorImported->invest_disbursement_id,
            'invest_return_id' => $anchorImported->invest_return_id,
            'status' => 'posted',
            'member_id' => $anchorImported->member_id,
        ]);
    }

    /**
     * Mark an additional operational row cleared as part of an N→1 group match.
     */
    public function markOperationalClearedInGroup(
        BankTransaction $operational,
        int $groupId,
        CarbonInterface $clearedAt,
    ): void {
        $operational->update([
            'is_cleared' => true,
            'cleared_at' => $clearedAt,
            'bank_clearance_match_group_id' => $groupId,
        ]);
    }
}
