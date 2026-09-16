<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

final class RiskSettings
{
    public const GROUP = 'risk';

    public static function cacheTtlSeconds(): int
    {
        return max(30, (int) Setting::get(self::GROUP, 'cache_ttl_seconds', 300));
    }

    public static function highScoreThreshold(): int
    {
        return (int) Setting::get(self::GROUP, 'high_score_threshold', 60);
    }

    public static function criticalScoreThreshold(): int
    {
        return (int) Setting::get(self::GROUP, 'critical_score_threshold', 80);
    }

    public static function blockLoanApprovalAtCritical(): bool
    {
        return (string) Setting::get(self::GROUP, 'block_loan_approval_at_critical', '0') === '1';
    }

    public static function guarantorExposureCap(): float
    {
        return (float) Setting::get(self::GROUP, 'guarantor_exposure_cap', 0);
    }

    public static function watchlistDigestWeekday(): int
    {
        return (int) Setting::get(self::GROUP, 'watchlist_digest_weekday', 1); // Monday
    }

    public static function watchlistDigestEnabled(): bool
    {
        return (string) Setting::get(self::GROUP, 'watchlist_digest_enabled', '1') === '1';
    }

    public static function mlEnabled(): bool
    {
        return (string) Setting::get(self::GROUP, 'ml_enabled', '0') === '1';
    }

    public static function mlMinLabels(): int
    {
        return max(5, (int) Setting::get(self::GROUP, 'ml_min_labels', 20));
    }

    public static function mlBlendWeight(): float
    {
        return min(1.0, max(0.0, (float) Setting::get(self::GROUP, 'ml_blend_weight', 0.35)));
    }
}
