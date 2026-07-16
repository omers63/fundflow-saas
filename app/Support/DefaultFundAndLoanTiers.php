<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical fund / loan tier defaults for a fresh tenant (Samman production shape).
 *
 * Loan amount bands stay 0–10; fund pools consolidate bands with overlapping allocation
 * percentages. Emergency pool stays unlinked.
 */
final class DefaultFundAndLoanTiers
{
    /**
     * @return list<array{tier_number: int, label: string, min_amount: float|int, max_amount: float|int, min_monthly_installment: float|int}>
     */
    public static function loanTiers(): array
    {
        return [
            ['tier_number' => 0, 'label' => '1K->5K - 500', 'min_amount' => 0, 'max_amount' => 5_000, 'min_monthly_installment' => 500],
            ['tier_number' => 1, 'label' => '6K->30K - 1K', 'min_amount' => 6_000, 'max_amount' => 30_000, 'min_monthly_installment' => 1_000],
            ['tier_number' => 2, 'label' => '31K->60K - 1.5K', 'min_amount' => 31_000, 'max_amount' => 60_000, 'min_monthly_installment' => 1_500],
            ['tier_number' => 3, 'label' => '61K->90K - 2K', 'min_amount' => 61_000, 'max_amount' => 90_000, 'min_monthly_installment' => 2_000],
            ['tier_number' => 4, 'label' => '91K->120K - 2.5K', 'min_amount' => 91_000, 'max_amount' => 120_000, 'min_monthly_installment' => 2_500],
            ['tier_number' => 5, 'label' => '121K->150K - 3K', 'min_amount' => 121_000, 'max_amount' => 150_000, 'min_monthly_installment' => 3_000],
            ['tier_number' => 6, 'label' => '151K->180K - 3.5K', 'min_amount' => 151_000, 'max_amount' => 180_000, 'min_monthly_installment' => 3_500],
            ['tier_number' => 7, 'label' => '181K->210K - 4K', 'min_amount' => 181_000, 'max_amount' => 210_000, 'min_monthly_installment' => 4_000],
            ['tier_number' => 8, 'label' => '211K->240K - 4.5K', 'min_amount' => 211_000, 'max_amount' => 240_000, 'min_monthly_installment' => 4_500],
            ['tier_number' => 9, 'label' => '241K->270K - 5K', 'min_amount' => 241_000, 'max_amount' => 270_000, 'min_monthly_installment' => 5_000],
            ['tier_number' => 10, 'label' => '271K->300K - 5.5K', 'min_amount' => 271_000, 'max_amount' => 300_000, 'min_monthly_installment' => 5_500],
        ];
    }

    /**
     * @return list<array{tier_number: int, label: string, percentage: float|int, loan_tier_numbers: list<int>}>
     */
    public static function fundTiers(): array
    {
        return [
            ['tier_number' => 0, 'label' => 'Emergency', 'percentage' => 100, 'loan_tier_numbers' => []],
            ['tier_number' => 1, 'label' => 'Tier 1', 'percentage' => 40, 'loan_tier_numbers' => [0, 1]],
            ['tier_number' => 2, 'label' => 'Tier 2', 'percentage' => 30, 'loan_tier_numbers' => [2, 3]],
            ['tier_number' => 3, 'label' => 'Tier 3', 'percentage' => 10, 'loan_tier_numbers' => [4, 5]],
            ['tier_number' => 4, 'label' => 'Tier 4', 'percentage' => 10, 'loan_tier_numbers' => [6, 7]],
            ['tier_number' => 5, 'label' => 'Tier 5', 'percentage' => 10, 'loan_tier_numbers' => [8, 9, 10]],
        ];
    }

    /**
     * Insert defaults when both tier tables exist and are empty (fresh tenant / empty reset).
     */
    public static function seedIfEmpty(): void
    {
        if (! Schema::hasTable('loan_tiers') || ! Schema::hasTable('fund_tiers')) {
            return;
        }

        if (! Schema::hasColumn('loan_tiers', 'fund_tier_id')) {
            return;
        }

        if (DB::table('loan_tiers')->exists() || DB::table('fund_tiers')->exists()) {
            return;
        }

        $now = now();

        foreach (self::loanTiers() as $row) {
            DB::table('loan_tiers')->insert([
                'tier_number' => $row['tier_number'],
                'label' => $row['label'],
                'min_amount' => $row['min_amount'],
                'max_amount' => $row['max_amount'],
                'min_monthly_installment' => $row['min_monthly_installment'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        /** @var array<int, int> $loanTierIdsByNumber */
        $loanTierIdsByNumber = DB::table('loan_tiers')->pluck('id', 'tier_number')->all();

        foreach (self::fundTiers() as $row) {
            $fundTierId = DB::table('fund_tiers')->insertGetId([
                'tier_number' => $row['tier_number'],
                'label' => $row['label'],
                'percentage' => $row['percentage'],
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($row['loan_tier_numbers'] as $loanTierNumber) {
                $loanTierId = $loanTierIdsByNumber[$loanTierNumber] ?? null;
                if ($loanTierId === null) {
                    continue;
                }

                DB::table('loan_tiers')->where('id', $loanTierId)->update([
                    'fund_tier_id' => $fundTierId,
                ]);
            }
        }
    }
}
