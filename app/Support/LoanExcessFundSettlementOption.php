<?php

declare(strict_types=1);

namespace App\Support;

use App\Services\Loans\LoanEarlySettlementService;

/**
 * How remaining fund above the member loan share is applied when using
 * {@see LoanFundingStrategy::SPLIT_WITH_EARLY_SETTLEMENT}.
 */
final class LoanExcessFundSettlementOption
{
    public const KEEP_IN_FUND = 'keep_in_fund';

    public const ROLL_UP = 'roll_up';

    public const SKIP_FUTURE = 'skip_future';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::KEEP_IN_FUND => __('Keep remaining balance in my fund account'),
            self::ROLL_UP => __('Apply remaining fund as early settlement (roll up schedule)'),
            self::SKIP_FUTURE => __('Apply remaining fund as early settlement (skip installments)'),
        ];
    }

    public static function defaultForApplication(): string
    {
        return self::KEEP_IN_FUND;
    }

    public static function isValid(?string $option): bool
    {
        return in_array($option, [self::KEEP_IN_FUND, self::ROLL_UP, self::SKIP_FUTURE], true);
    }

    public static function normalize(?string $option): string
    {
        return self::isValid($option) ? $option : self::KEEP_IN_FUND;
    }

    /**
     * Settlement schedule option for {@see LoanEarlySettlementService}, or null to keep fund.
     *
     * @return 'roll_up'|'skip_future'|null
     */
    public static function toSettlementOption(?string $option): ?string
    {
        return match (self::normalize($option)) {
            self::ROLL_UP => 'roll_up',
            self::SKIP_FUTURE => 'skip_future',
            default => null,
        };
    }

    public static function appliesAsEarlySettlement(?string $option): bool
    {
        return self::toSettlementOption($option) !== null;
    }

    public static function label(?string $option): string
    {
        $normalized = self::normalize($option);

        return self::options()[$normalized] ?? self::options()[self::KEEP_IN_FUND];
    }
}
