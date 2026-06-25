<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Legacy loan CSV identity helpers (column "Loan Id" normalizes to loan_id).
 */
final class LegacyLoanCsvIdentity
{
    /**
     * @param  array<string, string>  $row
     */
    public static function legacyLoanIdFromRow(array $row): ?int
    {
        foreach (['loan_id', 'legacy_loan_id'] as $key) {
            $raw = trim((string) ($row[$key] ?? ''));

            if ($raw === '' || ! ctype_digit($raw)) {
                continue;
            }

            $id = (int) $raw;

            if ($id > 0) {
                return $id;
            }
        }

        return null;
    }
}
