<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Contribution;

/**
 * Identifies contributions posted from legacy migration CSV imports.
 *
 * Legacy data may include both a contribution and loan repayments in the same cycle;
 * reconciliation must not treat those as live {@see CONTRIBUTION_EXEMPT_COLLECTED} violations.
 */
final class LegacyImportedContribution
{
    public static function isContribution(Contribution $contribution): bool
    {
        $notes = (string) ($contribution->notes ?? '');

        if ($notes === '') {
            return false;
        }

        return str_contains($notes, 'legacy-import:')
            || str_contains($notes, 'Legacy migration')
            || str_contains($notes, 'ترحيل البيانات التاريخية');
    }
}
