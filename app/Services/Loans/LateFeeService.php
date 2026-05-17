<?php

namespace App\Services\Loans;

use App\Models\Tenant\Setting;
use Carbon\Carbon;

/**
 * Tiered late fees by calendar days after the cycle deadline (contributions & repayments share the same day-count rules).
 * Tiers: ≥1, ≥10, ≥20, ≥30 days — the first matching tier with a non-zero SAR amount wins (highest threshold first); if that tier is 0, falls back to the next lower threshold.
 */
class LateFeeService
{
    /** @var list<int> Highest threshold first */
    private const DAY_THRESHOLDS = [30, 20, 10, 1];

    /**
     * Calendar whole days after the due instant: 0 if $at is on or before $dueEnd.
     */
    public function daysPastDue(Carbon $dueEnd, Carbon $at): int
    {
        if ($at->lte($dueEnd)) {
            return 0;
        }

        return (int) $dueEnd->copy()->startOfDay()->diffInDays($at->copy()->startOfDay(), false);
    }

    public function contributionLateFeeForDays(int $daysPast): float
    {
        return $this->tieredAmount($daysPast, 'late_fee.contribution_day');
    }

    public function repaymentLateFeeForDays(int $daysPast): float
    {
        return $this->tieredAmount($daysPast, 'late_fee.repayment_day');
    }

    private function tieredAmount(int $daysPast, string $keyPrefix): float
    {
        if ($daysPast < 1) {
            return 0.0;
        }

        foreach (self::DAY_THRESHOLDS as $minDays) {
            if ($daysPast < $minDays) {
                continue;
            }
            $key = "{$keyPrefix}_{$minDays}";
            $fee = max(0.0, (float) Setting::get('late_fee', $key, 0));
            if ($fee > 0.00001) {
                return $fee;
            }
        }

        foreach (self::DAY_THRESHOLDS as $minDays) {
            if ($daysPast < $minDays) {
                continue;
            }
            $key = "{$keyPrefix}_{$minDays}";

            return max(0.0, (float) Setting::get('late_fee', $key, 0));
        }

        return 0.0;
    }
}
