<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\Loans\LoanDelinquencyService;

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
            'late_settlement_consecutive_threshold' => 3,
            'late_settlement_rolling_threshold' => 15,
            'late_settlement_lookback_months' => 60,
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
            // 0 = amount-only for the Match picker (no date window). Auto-match still uses bank_match_date_range_days.
            'bank_match_manual_date_range_days' => 0,
            'stale_pending_days' => 30,
            'cash_deposit_unbanked_days' => 14,
            'timing_diff_defer_hours' => 24,
            'timing_diff_escalate_hours' => 48,
            // When cash is short: debit available balance (true) or leave unpaid until full cash (false).
            'allow_partial_open_cycle' => true,
            'allow_partial_arrears' => true,
            'allow_partial_delinquency' => true,
        ];
    }

    public const PARTIAL_CONTEXT_OPEN_CYCLE = 'open_cycle';

    public const PARTIAL_CONTEXT_ARREARS = 'arrears';

    public const PARTIAL_CONTEXT_DELINQUENCY = 'delinquency';

    public static function allowPartialOpenCycle(): bool
    {
        return self::boolCollectionGet('allow_partial_open_cycle', true);
    }

    public static function allowPartialArrears(): bool
    {
        return self::boolCollectionGet('allow_partial_arrears', true);
    }

    public static function allowPartialDelinquency(): bool
    {
        return self::boolCollectionGet('allow_partial_delinquency', true);
    }

    /**
     * Whether contribution / EMI collection may debit less than the full amount due
     * for the given context. Delinquency (policy-breach members) is checked first by callers.
     */
    public static function allowsPartialCollection(string $context): bool
    {
        return match ($context) {
            self::PARTIAL_CONTEXT_OPEN_CYCLE => self::allowPartialOpenCycle(),
            self::PARTIAL_CONTEXT_ARREARS => self::allowPartialArrears(),
            self::PARTIAL_CONTEXT_DELINQUENCY => self::allowPartialDelinquency(),
            default => true,
        };
    }

    /**
     * Resolve which partial-collection policy applies.
     *
     * Delinquent members always use the delinquency toggle. Otherwise open-cycle
     * vs arrears depends on whether the obligation is still in the live open period.
     */
    public static function resolvePartialCollectionContext(Member $member, bool $isOpenCycleObligation): string
    {
        if (app(LoanDelinquencyService::class)->isDelinquent($member)) {
            return self::PARTIAL_CONTEXT_DELINQUENCY;
        }

        return $isOpenCycleObligation
            ? self::PARTIAL_CONTEXT_OPEN_CYCLE
            : self::PARTIAL_CONTEXT_ARREARS;
    }

    public static function allowsPartialCollectionFor(Member $member, bool $isOpenCycleObligation): bool
    {
        return self::allowsPartialCollection(
            self::resolvePartialCollectionContext($member, $isOpenCycleObligation),
        );
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

    /**
     * Date window (± days) for the Work queue Match picker.
     * 0 means amount-only (no date filter); closest dates are still sorted first.
     */
    public static function bankMatchManualDateRangeDays(): int
    {
        return max(0, (int) self::collectionGet('bank_match_manual_date_range_days', 0));
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

    public static function lateSettlementConsecutiveThreshold(): int
    {
        return max(1, (int) self::delinquencyGet('late_settlement_consecutive_threshold', 3));
    }

    public static function lateSettlementRollingThreshold(): int
    {
        return max(1, (int) self::delinquencyGet('late_settlement_rolling_threshold', 15));
    }

    public static function lateSettlementLookbackMonths(): int
    {
        return max(1, (int) self::delinquencyGet('late_settlement_lookback_months', 60));
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
        $collection = array_merge(self::collectionDefaults(), Setting::getGroup(self::GROUP_COLLECTION));

        return [
            'delinquency_consecutive' => $delinquency['consecutive_miss_threshold'],
            'delinquency_total' => $delinquency['total_miss_threshold'],
            'delinquency_lookback_months' => $delinquency['total_miss_lookback_months'],
            'contribution_late_settlement_consecutive' => $delinquency['late_settlement_consecutive_threshold']
                ?? self::delinquencyDefaults()['late_settlement_consecutive_threshold'],
            'contribution_late_settlement_rolling' => $delinquency['late_settlement_rolling_threshold']
                ?? self::delinquencyDefaults()['late_settlement_rolling_threshold'],
            'contribution_late_settlement_lookback_months' => $delinquency['late_settlement_lookback_months']
                ?? self::delinquencyDefaults()['late_settlement_lookback_months'],
            'late_fee_contribution_1d' => $lateFee['contribution_day_1'],
            'late_fee_contribution_10d' => $lateFee['contribution_day_10'],
            'late_fee_contribution_20d' => $lateFee['contribution_day_20'],
            'late_fee_contribution_30d' => $lateFee['contribution_day_30'],
            'late_fee_repayment_1d' => $lateFee['repayment_day_1'],
            'late_fee_repayment_10d' => $lateFee['repayment_day_10'],
            'late_fee_repayment_20d' => $lateFee['repayment_day_20'],
            'late_fee_repayment_30d' => $lateFee['repayment_day_30'],
            'annual_subscription_fee' => self::annualSubscriptionFee(),
            'collection_late_fee_reminder_days' => $collection['late_fee_reminder_days'],
            'collection_late_fee_tier_1_day' => $collection['late_fee_tier_1_day'],
            'collection_late_fee_tier_2_day' => $collection['late_fee_tier_2_day'],
            'collection_late_fee_tier_3_day' => $collection['late_fee_tier_3_day'],
            'collection_late_fee_tier_4_day' => $collection['late_fee_tier_4_day'],
            'collection_late_fee_model' => $collection['late_fee_model'],
            'collection_recon_tolerance' => $collection['recon_tolerance'],
            'collection_bank_match_date_range_days' => $collection['bank_match_date_range_days'],
            'collection_bank_match_manual_date_range_days' => $collection['bank_match_manual_date_range_days'] ?? 0,
            'collection_stale_pending_days' => $collection['stale_pending_days'],
            'collection_cash_deposit_unbanked_days' => $collection['cash_deposit_unbanked_days'],
            'collection_timing_diff_defer_hours' => $collection['timing_diff_defer_hours'],
            'collection_timing_diff_escalate_hours' => $collection['timing_diff_escalate_hours'],
            'collection_allow_partial_open_cycle' => filter_var($collection['allow_partial_open_cycle'] ?? true, FILTER_VALIDATE_BOOL),
            'collection_allow_partial_arrears' => filter_var($collection['allow_partial_arrears'] ?? true, FILTER_VALIDATE_BOOL),
            'collection_allow_partial_delinquency' => filter_var($collection['allow_partial_delinquency'] ?? true, FILTER_VALIDATE_BOOL),
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
        Setting::set(self::GROUP_DELINQUENCY, 'late_settlement_consecutive_threshold', max(1, min(36, (int) ($state['contribution_late_settlement_consecutive'] ?? 3))));
        Setting::set(self::GROUP_DELINQUENCY, 'late_settlement_rolling_threshold', max(1, min(240, (int) ($state['contribution_late_settlement_rolling'] ?? 15))));
        Setting::set(self::GROUP_DELINQUENCY, 'late_settlement_lookback_months', max(1, min(240, (int) ($state['contribution_late_settlement_lookback_months'] ?? 60))));

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

        Setting::set(self::GROUP_COLLECTION, 'late_fee_reminder_days', max(0, (int) ($state['collection_late_fee_reminder_days'] ?? 3)));
        Setting::set(self::GROUP_COLLECTION, 'late_fee_tier_1_day', max(1, (int) ($state['collection_late_fee_tier_1_day'] ?? 3)));
        Setting::set(self::GROUP_COLLECTION, 'late_fee_tier_2_day', max(1, (int) ($state['collection_late_fee_tier_2_day'] ?? 10)));
        Setting::set(self::GROUP_COLLECTION, 'late_fee_tier_3_day', max(1, (int) ($state['collection_late_fee_tier_3_day'] ?? 20)));
        Setting::set(self::GROUP_COLLECTION, 'late_fee_tier_4_day', max(1, (int) ($state['collection_late_fee_tier_4_day'] ?? 30)));
        $lateFeeModel = $state['collection_late_fee_model'] ?? 'replacement';

        Setting::set(self::GROUP_COLLECTION, 'late_fee_model', in_array($lateFeeModel, ['replacement', 'cumulative'], true)
            ? $lateFeeModel
            : 'replacement');
        Setting::set(self::GROUP_COLLECTION, 'recon_tolerance', max(0.0, (float) ($state['collection_recon_tolerance'] ?? 0.01)));
        Setting::set(self::GROUP_COLLECTION, 'bank_match_date_range_days', max(0, (int) ($state['collection_bank_match_date_range_days'] ?? 3)));
        Setting::set(self::GROUP_COLLECTION, 'bank_match_manual_date_range_days', max(0, (int) ($state['collection_bank_match_manual_date_range_days'] ?? 0)));
        Setting::set(self::GROUP_COLLECTION, 'stale_pending_days', max(1, (int) ($state['collection_stale_pending_days'] ?? 30)));
        Setting::set(self::GROUP_COLLECTION, 'cash_deposit_unbanked_days', max(1, (int) ($state['collection_cash_deposit_unbanked_days'] ?? 14)));
        Setting::set(self::GROUP_COLLECTION, 'timing_diff_defer_hours', max(1, (int) ($state['collection_timing_diff_defer_hours'] ?? 24)));
        Setting::set(self::GROUP_COLLECTION, 'timing_diff_escalate_hours', max(1, (int) ($state['collection_timing_diff_escalate_hours'] ?? 48)));
        Setting::set(self::GROUP_COLLECTION, 'allow_partial_open_cycle', (bool) ($state['collection_allow_partial_open_cycle'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP_COLLECTION, 'allow_partial_arrears', (bool) ($state['collection_allow_partial_arrears'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP_COLLECTION, 'allow_partial_delinquency', (bool) ($state['collection_allow_partial_delinquency'] ?? true) ? '1' : '0');
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

    private static function boolCollectionGet(string $key, bool $default): bool
    {
        $value = Setting::get(self::GROUP_COLLECTION, $key);

        if ($value === null) {
            return $default;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
