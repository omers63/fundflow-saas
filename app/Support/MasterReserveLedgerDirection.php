<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Account;
use InvalidArgumentException;

/**
 * Reserve-ledger import routing for master invest, expense, and fees accounts.
 *
 * CSV type column uses credit / debit; credit maps to funding-style workflows and debit to disburse-style workflows.
 */
final class MasterReserveLedgerDirection
{
    /** @var list<string> */
    public const RESERVE_TYPES = ['expense', 'fees', 'invest'];

    public static function isReserveLedger(Account $account): bool
    {
        return $account->is_master && in_array($account->type, self::RESERVE_TYPES, true);
    }

    /**
     * @return 'in'|'out'|null
     */
    public static function workflowFromLedgerType(string $ledgerType): ?string
    {
        return match ($ledgerType) {
            'credit' => 'in',
            'debit' => 'out',
            default => null,
        };
    }

    /**
     * @return 'credit'|'debit'
     */
    public static function normalizeImportType(string $raw): string
    {
        $type = strtolower(trim($raw));

        return match ($type) {
            'credit', 'in', 'fund', 'deduct' => 'credit',
            'debit', 'out', 'disburse' => 'debit',
            default => throw new InvalidArgumentException(__('Type must be credit or debit.')),
        };
    }

    public static function importTypeHint(Account $account): string
    {
        if (! self::isReserveLedger($account)) {
            return __('credit or debit');
        }

        return match ($account->type) {
            'invest' => __('credit (invest return) or debit (invest out / disbursement)'),
            'expense' => __('credit (fund) or debit (disburse)'),
            'fees' => __('credit (deduct; member_number required) or debit (disburse)'),
            default => __('credit or debit'),
        };
    }
}
