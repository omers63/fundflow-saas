<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

final class ContributionPolicySettings
{
    public const GROUP_DELINQUENCY = 'delinquency';

    public const GROUP_LATE_FEE = 'late_fee';

    public const GROUP_COLLECTION = 'collection';

    public const GROUP_SUBSCRIPTION = 'subscription';

    /**
     * @return array<string, mixed>
     */
    public static function delinquencyDefaults(): array
    {
        return [
            'consecutive_miss_threshold' => 3,
            'total_miss_threshold' => 15,
            'total_miss_lookback_months' => 60,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function collectionDefaults(): array
    {
        return [
            'late_fee_reminder_days' => 3,
            'late_fee_tier_1_day' => 3,
            'late_fee_tier_2_day' => 10,
            'late_fee_tier_3_day' => 20,
            'late_fee_tier_4_day' => 30,
            'late_fee_model' => 'replacement',
            'recon_tolerance' => 0.01,
            'bank_match_date_range_days' => 3,
            'stale_pending_days' => 30,
            'cash_deposit_unbanked_days' => 14,
            'timing_diff_defer_hours' => 24,
            'timing_diff_escalate_hours' => 48,
        ];
    }

    public static function timingDiffDeferHours(): int
    {
        return max(1, (int) self::collectionGet('timing_diff_defer_hours', 24));
    }

    public static function timingDiffEscalateHours(): int
    {
        return max(1, (int) self::collectionGet('timing_diff_escalate_hours', 48));
    }

    public static function stalePendingDays(): int
    {
        return max(1, (int) self::collectionGet('stale_pending_days', 30));
    }

    public static function cashDepositUnbankedDays(): int
    {
        return max(1, (int) self::collectionGet('cash_deposit_unbanked_days', 14));
    }

    public static function lateFeeDefaults(): array
    {
        return [
            'contribution_day_3' => 0,
            'contribution_day_10' => 0,
            'contribution_day_20' => 0,
            'contribution_day_30' => 0,
            'repayment_day_3' => 0,
            'repayment_day_10' => 0,
            'repayment_day_20' => 0,
            'repayment_day_30' => 0,
            // Legacy keys (read fallback)
            'contribution_day_1' => 0,
            'repayment_day_1' => 0,
        ];
    }

    public static function lateFeeReminderDays(): int
    {
        return max(0, (int) self::collectionGet('late_fee_reminder_days', 3));
    }

    public static function lateFeeTier1Day(): int
    {
        return max(1, (int) self::collectionGet('late_fee_tier_1_day', 3));
    }

    public static function lateFeeTier2Day(): int
    {
        return max(1, (int) self::collectionGet('late_fee_tier_2_day', 10));
    }

    public static function lateFeeTier3Day(): int
    {
        return max(1, (int) self::collectionGet('late_fee_tier_3_day', 20));
    }

    public static function lateFeeTier4Day(): int
    {
        return max(1, (int) self::collectionGet('late_fee_tier_4_day', 30));
    }

    public static function lateFeeModel(): string
    {
        $model = (string) self::collectionGet('late_fee_model', 'replacement');

        return in_array($model, ['replacement', 'cumulative'], true) ? $model : 'replacement';
    }

    public static function reconTolerance(): float
    {
        return max(0.0, (float) self::collectionGet('recon_tolerance', 0.01));
    }

    public static function bankMatchDateRangeDays(): int
    {
        return max(0, (int) self::collectionGet('bank_match_date_range_days', 3));
    }

    public static function consecutiveMissThreshold(): int
    {
        return max(1, (int) self::delinquencyGet('consecutive_miss_threshold', 3));
    }

    public static function totalMissThreshold(): int
    {
        return max(1, (int) self::delinquencyGet('total_miss_threshold', 15));
    }

    public static function totalMissLookbackMonths(): int
    {
        return max(1, (int) self::delinquencyGet('total_miss_lookback_months', 60));
    }

    public static function annualSubscriptionFee(): float
    {
        return max(0.0, (float) Setting::get(self::GROUP_SUBSCRIPTION, 'annual_fee', 0));
    }

    /**
     * @return array<string, mixed>
     */
    public static function allForForm(): array
    {
        $delinquency = array_merge(self::delinquencyDefaults(), Setting::getGroup(self::GROUP_DELINQUENCY));
        $lateFee = array_merge(self::lateFeeDefaults(), Setting::getGroup(self::GROUP_LATE_FEE));

        return [
            'delinquency_consecutive' => $delinquency['consecutive_miss_threshold'],
            'delinquency_total' => $delinquency['total_miss_threshold'],
            'delinquency_lookback_months' => $delinquency['total_miss_lookback_months'],
            'late_fee_contribution_1d' => $lateFee['contribution_day_1'],
            'late_fee_contribution_10d' => $lateFee['contribution_day_10'],
            'late_fee_contribution_20d' => $lateFee['contribution_day_20'],
            'late_fee_contribution_30d' => $lateFee['contribution_day_30'],
            'late_fee_repayment_1d' => $lateFee['repayment_day_1'],
            'late_fee_repayment_10d' => $lateFee['repayment_day_10'],
            'late_fee_repayment_20d' => $lateFee['repayment_day_20'],
            'late_fee_repayment_30d' => $lateFee['repayment_day_30'],
            'annual_subscription_fee' => self::annualSubscriptionFee(),
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function saveFromForm(array $state): void
    {
        Setting::set(self::GROUP_DELINQUENCY, 'consecutive_miss_threshold', max(1, min(36, (int) ($state['delinquency_consecutive'] ?? 3))));
        Setting::set(self::GROUP_DELINQUENCY, 'total_miss_threshold', max(1, min(240, (int) ($state['delinquency_total'] ?? 15))));
        Setting::set(self::GROUP_DELINQUENCY, 'total_miss_lookback_months', max(1, min(240, (int) ($state['delinquency_lookback_months'] ?? 60))));

        foreach (self::lateFeeDefaults() as $key => $default) {
            $formKey = match ($key) {
                'contribution_day_1' => 'late_fee_contribution_1d',
                'contribution_day_10' => 'late_fee_contribution_10d',
                'contribution_day_20' => 'late_fee_contribution_20d',
                'contribution_day_30' => 'late_fee_contribution_30d',
                'repayment_day_1' => 'late_fee_repayment_1d',
                'repayment_day_10' => 'late_fee_repayment_10d',
                'repayment_day_20' => 'late_fee_repayment_20d',
                'repayment_day_30' => 'late_fee_repayment_30d',
                default => null,
            };

            if ($formKey === null) {
                continue;
            }

            Setting::set(self::GROUP_LATE_FEE, $key, max(0, (float) ($state[$formKey] ?? $default)));
        }

        Setting::set(self::GROUP_SUBSCRIPTION, 'annual_fee', max(0, (float) ($state['annual_subscription_fee'] ?? 0)));
    }

    private static function delinquencyGet(string $key, mixed $default): mixed
    {
        $value = Setting::get(self::GROUP_DELINQUENCY, $key);

        return $value !== null ? $value : $default;
    }

    private static function collectionGet(string $key, mixed $default): mixed
    {
        $value = Setting::get(self::GROUP_COLLECTION, $key);

        return $value !== null ? $value : $default;
    }
}
