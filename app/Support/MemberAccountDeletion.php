<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Account;
use InvalidArgumentException;

final class MemberAccountDeletion
{
    public static function canDelete(Account $account): bool
    {
        return self::blockReason($account) === null;
    }

    public static function blockReason(Account $account): ?string
    {
        if ($account->is_master) {
            return __('Master accounts cannot be deleted here.');
        }

        if ($account->loan_id !== null) {
            return __('Loan ledger accounts cannot be deleted here.');
        }

        if (round((float) $account->balance, 2) !== 0.0) {
            return __('Account balance must be zero before deletion.');
        }

        return null;
    }

    public static function ensureCanDelete(Account $account): void
    {
        $reason = self::blockReason($account);

        if ($reason !== null) {
            throw new InvalidArgumentException($reason);
        }
    }

    public static function modalDescription(Account $account): string
    {
        $transactionCount = $account->transactions()->count();

        if ($transactionCount > 0) {
            return __('This will permanently delete the account and :count ledger transaction(s).', [
                'count' => $transactionCount,
            ]);
        }

        return __('This will permanently delete the account.');
    }
}
