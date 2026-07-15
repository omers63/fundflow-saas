<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

/**
 * Tenant settings for the loan queue projected approval / disbursement engine.
 */
final class LoanQueueProjectionSettings
{
    public const GROUP = 'loan_queue_projection';

    public const SCOPE_WITHIN_TIER = 'within_tier';

    public const SCOPE_ACROSS_ALL_TIERS = 'across_all_tiers';

    public const SCOPE_PENDING_WITHIN_TIER = 'within_tier';

    public const SCOPE_PENDING_ACROSS_ALL = 'across_all';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'queued_demand_scope' => self::SCOPE_WITHIN_TIER,
            'pending_demand_scope' => self::SCOPE_PENDING_WITHIN_TIER,
            'include_open_period_contributions' => true,
            'include_contribution_arrears' => false,
            'emi_forecast_months' => 3,
            'use_forward_inflow' => true,
            'use_historical_inflow' => true,
            'historical_lookback_months' => 3,
            'apply_tier_allocation_percent' => true,
            'max_months_display' => 6,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return array_merge(self::defaults(), Setting::getGroup(self::GROUP));
    }

    /**
     * @return array<string, mixed>
     */
    public static function allForForm(): array
    {
        $settings = self::all();

        return [
            'lqp_queued_demand_scope' => $settings['queued_demand_scope'],
            'lqp_pending_demand_scope' => $settings['pending_demand_scope'],
            'lqp_include_open_contributions' => (bool) $settings['include_open_period_contributions'],
            'lqp_include_contribution_arrears' => (bool) $settings['include_contribution_arrears'],
            'lqp_emi_forecast_months' => (int) $settings['emi_forecast_months'],
            'lqp_use_forward_inflow' => (bool) $settings['use_forward_inflow'],
            'lqp_use_historical_inflow' => (bool) $settings['use_historical_inflow'],
            'lqp_historical_lookback_months' => (int) $settings['historical_lookback_months'],
            'lqp_apply_tier_allocation' => (bool) $settings['apply_tier_allocation_percent'],
            'lqp_max_months_display' => (int) $settings['max_months_display'],
        ];
    }

    /**
     * @param  array<string, mixed>  $state
     */
    public static function saveFromForm(array $state): void
    {
        $queuedScope = $state['lqp_queued_demand_scope'] ?? self::SCOPE_WITHIN_TIER;
        $pendingScope = $state['lqp_pending_demand_scope'] ?? self::SCOPE_PENDING_WITHIN_TIER;

        Setting::set(self::GROUP, 'queued_demand_scope', in_array($queuedScope, [self::SCOPE_WITHIN_TIER, self::SCOPE_ACROSS_ALL_TIERS], true)
            ? $queuedScope
            : self::SCOPE_WITHIN_TIER);
        Setting::set(self::GROUP, 'pending_demand_scope', in_array($pendingScope, [self::SCOPE_PENDING_WITHIN_TIER, self::SCOPE_PENDING_ACROSS_ALL], true)
            ? $pendingScope
            : self::SCOPE_PENDING_WITHIN_TIER);
        Setting::set(self::GROUP, 'include_open_period_contributions', ($state['lqp_include_open_contributions'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'include_contribution_arrears', ($state['lqp_include_contribution_arrears'] ?? false) ? '1' : '0');
        Setting::set(self::GROUP, 'emi_forecast_months', max(1, min(24, (int) ($state['lqp_emi_forecast_months'] ?? 3))));
        Setting::set(self::GROUP, 'use_forward_inflow', ($state['lqp_use_forward_inflow'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'use_historical_inflow', ($state['lqp_use_historical_inflow'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'historical_lookback_months', max(1, min(36, (int) ($state['lqp_historical_lookback_months'] ?? 3))));
        Setting::set(self::GROUP, 'apply_tier_allocation_percent', ($state['lqp_apply_tier_allocation'] ?? true) ? '1' : '0');
        Setting::set(self::GROUP, 'max_months_display', max(1, min(24, (int) ($state['lqp_max_months_display'] ?? 6))));
    }

    public static function queuedDemandScope(): string
    {
        $scope = (string) self::get('queued_demand_scope', self::SCOPE_WITHIN_TIER);

        return $scope === self::SCOPE_ACROSS_ALL_TIERS ? self::SCOPE_ACROSS_ALL_TIERS : self::SCOPE_WITHIN_TIER;
    }

    public static function pendingDemandScope(): string
    {
        $scope = (string) self::get('pending_demand_scope', self::SCOPE_PENDING_WITHIN_TIER);

        return $scope === self::SCOPE_PENDING_ACROSS_ALL ? self::SCOPE_PENDING_ACROSS_ALL : self::SCOPE_PENDING_WITHIN_TIER;
    }

    public static function includeOpenPeriodContributions(): bool
    {
        return self::bool('include_open_period_contributions', true);
    }

    public static function includeContributionArrears(): bool
    {
        return self::bool('include_contribution_arrears', false);
    }

    public static function emiForecastMonths(): int
    {
        return max(1, min(24, (int) self::get('emi_forecast_months', 3)));
    }

    public static function useForwardInflow(): bool
    {
        return self::bool('use_forward_inflow', true);
    }

    public static function useHistoricalInflow(): bool
    {
        return self::bool('use_historical_inflow', true);
    }

    public static function historicalLookbackMonths(): int
    {
        return max(1, min(36, (int) self::get('historical_lookback_months', 3)));
    }

    public static function applyTierAllocationPercent(): bool
    {
        return self::bool('apply_tier_allocation_percent', true);
    }

    public static function maxMonthsDisplay(): int
    {
        return max(1, min(24, (int) self::get('max_months_display', 6)));
    }

    /**
     * @return array<string, string>
     */
    public static function queuedDemandScopeOptions(): array
    {
        return [
            self::SCOPE_WITHIN_TIER => __('Within fund tier only'),
            self::SCOPE_ACROSS_ALL_TIERS => __('Across all fund tiers'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function pendingDemandScopeOptions(): array
    {
        return [
            self::SCOPE_PENDING_WITHIN_TIER => __('Within expected fund tier only'),
            self::SCOPE_PENDING_ACROSS_ALL => __('All pending applications (fund-wide)'),
        ];
    }

    private static function get(string $key, mixed $default): mixed
    {
        $value = Setting::get(self::GROUP, $key);

        return $value !== null ? $value : $default;
    }

    private static function bool(string $key, bool $default): bool
    {
        $value = self::get($key, $default);

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOL);
    }
}
