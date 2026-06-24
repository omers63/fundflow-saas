<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

/**
 * Tenant setting for how legacy migration infers loan funding when CSV rows omit portions.
 */
final class LegacyMigrationFundingStrategySettings
{
    public const GROUP = 'legacy_migration';

    public const KEY = 'loan_funding_strategy';

    public static function defaultFundingStrategy(): string
    {
        return LoanFundingStrategy::MEMBER_FUND_TOPUP;
    }

    /**
     * @return array<string, string>
     */
    public static function fundingStrategyOptions(): array
    {
        return [
            LoanFundingStrategy::MEMBER_FUND_TOPUP => __('Use member fund balance (master tops up the rest)'),
            LoanFundingStrategy::SPLIT_PERCENTAGE => __('Use fund split (:pct% member / :master_pct% master)', [
                'pct' => number_format(LoanSettings::memberFundingSplitPercent(), 1),
                'master_pct' => number_format(LoanSettings::masterFundingSplitPercent(), 1),
            ]),
        ];
    }

    public static function fundingStrategy(): string
    {
        $stored = Setting::get(self::GROUP, self::KEY);

        if (!is_string($stored) || $stored === '') {
            return self::defaultFundingStrategy();
        }

        return LoanFundingStrategy::normalize($stored);
    }

    public static function saveFundingStrategy(string $strategy): void
    {
        Setting::set(self::GROUP, self::KEY, LoanFundingStrategy::normalize($strategy));
    }
}
