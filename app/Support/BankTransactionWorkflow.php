<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\BankTransaction;
use App\Services\BankClearingMatchService;

/**
 * Bank statement line lifecycle: import → post to cash → post to member,
 * or match-only when ledger posting was already done via fund posting / cash-out.
 */
final class BankTransactionWorkflow
{
    public static function isLinkedToOperationalRequest(BankTransaction $transaction): bool
    {
        return $transaction->fund_posting_id !== null
            || $transaction->cash_out_request_id !== null
            || $transaction->membership_application_id !== null;
    }

    public static function isSyntheticOperationalStatement(BankTransaction $transaction): bool
    {
        return app(BankClearingMatchService::class)
            ->isSyntheticOperationalStatement($transaction);
    }

    /**
     * Real CSV import lines only — not synthetic clearance rows or match-only imports.
     */
    public static function canPostToCash(BankTransaction $transaction): bool
    {
        return $transaction->status === 'imported'
            && ! self::isLinkedToOperationalRequest($transaction)
            && ! self::isSyntheticOperationalStatement($transaction);
    }

    /**
     * Direct bank import workflow — not when deposit/cash-out was already posted.
     */
    public static function canPostToMember(BankTransaction $transaction): bool
    {
        return in_array($transaction->status, ['imported', 'mirrored'], true)
            && ! self::isLinkedToOperationalRequest($transaction)
            && ! self::isSyntheticOperationalStatement($transaction);
    }
}
