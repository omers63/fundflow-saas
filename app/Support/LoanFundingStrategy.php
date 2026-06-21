<?php

declare(strict_types=1);

namespace App\Support;

final class LoanFundingStrategy
{
    public const MEMBER_FUND_TOPUP = 'member_fund_topup';

    public const SPLIT_PERCENTAGE = 'split_percentage';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::MEMBER_FUND_TOPUP => __('Use my fund balance (master tops up the rest)'),
            self::SPLIT_PERCENTAGE => __('Use the fund split defined by the fund (:pct% from my fund)', [
                'pct' => number_format(LoanSettings::memberFundingSplitPercent(), 1),
            ]),
        ];
    }

    /**
     * Funding strategies enabled for new loan applications.
     *
     * @return array<string, string>
     */
    public static function availableOptions(): array
    {
        $options = [];

        if (LoanSettings::allowMemberFundTopupStrategy()) {
            $options[self::MEMBER_FUND_TOPUP] = self::options()[self::MEMBER_FUND_TOPUP];
        }

        if (LoanSettings::allowSplitPercentageStrategy()) {
            $options[self::SPLIT_PERCENTAGE] = self::options()[self::SPLIT_PERCENTAGE];
        }

        return $options;
    }

    public static function defaultForApplication(): string
    {
        $available = self::availableOptions();

        if ($available === []) {
            return self::MEMBER_FUND_TOPUP;
        }

        return array_key_first($available);
    }

    public static function isAvailableForApplication(?string $strategy): bool
    {
        return self::isValid($strategy) && array_key_exists($strategy, self::availableOptions());
    }

    public static function isValid(?string $strategy): bool
    {
        return in_array($strategy, [self::MEMBER_FUND_TOPUP, self::SPLIT_PERCENTAGE], true);
    }

    public static function normalize(?string $strategy): string
    {
        return self::isValid($strategy) ? $strategy : self::MEMBER_FUND_TOPUP;
    }
}
