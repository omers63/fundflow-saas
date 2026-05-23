<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Setting;
use App\Support\ContributionPolicySettings;
use Carbon\Carbon;

/**
 * Tiered late fees after the collection window closes.
 * Days 1–N (reminder window): no fee. Tier thresholds default to 3 / 10 / 20 days overdue.
 */
class LateFeeService
{
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
        return $this->tieredAmount($daysPast, 'contribution');
    }

    public function repaymentLateFeeForDays(int $daysPast): float
    {
        return $this->tieredAmount($daysPast, 'repayment');
    }

    public function contributionLateFeeForTier(int $tier): float
    {
        return $this->feeForTier($tier, 'contribution');
    }

    private function tieredAmount(int $daysPast, string $prefix): float
    {
        if ($daysPast <= ContributionPolicySettings::lateFeeReminderDays()) {
            return 0.0;
        }

        $tier = match (true) {
            $daysPast >= ContributionPolicySettings::lateFeeTier3Day() => 3,
            $daysPast >= ContributionPolicySettings::lateFeeTier2Day() => 2,
            $daysPast > ContributionPolicySettings::lateFeeReminderDays() => 1,
            default => 0,
        };

        if ($tier === 0) {
            return 0.0;
        }

        return $this->feeForTier($tier, $prefix);
    }

    private function feeForTier(int $tier, string $prefix): float
    {
        $dayKey = match ($tier) {
            3 => 20,
            2 => 10,
            default => 3,
        };

        $keys = [
            "{$prefix}_day_{$dayKey}",
            "{$prefix}_day_{$dayKey}d",
            $tier === 1 ? "{$prefix}_day_1" : null,
            $tier === 1 ? "{$prefix}_day_3" : null,
        ];

        foreach (array_filter($keys) as $key) {
            $fee = max(0.0, (float) Setting::get('late_fee', $key, 0));
            if ($fee > 0.00001) {
                return $fee;
            }
        }

        return 0.0;
    }
}
