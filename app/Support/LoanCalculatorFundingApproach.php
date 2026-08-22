<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Combined calculator funding choices: member top-up plus the four excess-fund applications.
 */
final class LoanCalculatorFundingApproach
{
    public const MEMBER_FUND_TOPUP = LoanFundingStrategy::MEMBER_FUND_TOPUP;

    public const KEEP_IN_FUND = 'keep_in_fund';

    public const CASH_OUT = 'cash_out';

    public const ROLL_UP = 'roll_up';

    public const SKIP_FUTURE = 'skip_future';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        $options = [];

        if (LoanSettings::allowMemberFundTopupStrategy()) {
            $options[self::MEMBER_FUND_TOPUP] = LoanFundingStrategy::options()[self::MEMBER_FUND_TOPUP];
        }

        if (LoanSettings::allowSplitPercentageStrategy() || LoanSettings::allowSplitWithEarlySettlementStrategy()) {
            $options[self::KEEP_IN_FUND] = __('Keep remaining balance in my fund account');
        }

        if (
            LoanSettings::allowSplitPercentageStrategy()
            && LoanSettings::allowExcessFundCashOut()
        ) {
            $options[self::CASH_OUT] = __('Transfer excess to my cash account at disbursement');
        }

        if (LoanSettings::allowSplitWithEarlySettlementStrategy()) {
            $options[self::ROLL_UP] = __('Apply remaining fund as early settlement (roll up schedule)');
            $options[self::SKIP_FUTURE] = __('Apply remaining fund as early settlement (skip installments)');
        }

        return $options;
    }

    public static function defaultForApplication(): string
    {
        $available = self::options();

        if ($available === []) {
            return self::MEMBER_FUND_TOPUP;
        }

        return array_key_first($available);
    }

    public static function isValid(?string $approach): bool
    {
        return in_array($approach, [
            self::MEMBER_FUND_TOPUP,
            self::KEEP_IN_FUND,
            self::CASH_OUT,
            self::ROLL_UP,
            self::SKIP_FUTURE,
        ], true);
    }

    public static function normalize(?string $approach): string
    {
        $normalized = self::isValid($approach) ? (string) $approach : self::defaultForApplication();
        $available = self::options();

        if ($available !== [] && ! array_key_exists($normalized, $available)) {
            return array_key_first($available);
        }

        return $normalized;
    }

    public static function toFundingStrategy(?string $approach): string
    {
        return match (self::normalize($approach)) {
            self::MEMBER_FUND_TOPUP => LoanFundingStrategy::MEMBER_FUND_TOPUP,
            self::ROLL_UP, self::SKIP_FUTURE => LoanFundingStrategy::SPLIT_WITH_EARLY_SETTLEMENT,
            default => LoanFundingStrategy::SPLIT_PERCENTAGE,
        };
    }

    public static function toExcessDisposition(?string $approach): string
    {
        return self::normalize($approach) === self::CASH_OUT
            ? LoanFundExcessDisposition::CASH_OUT
            : LoanFundExcessDisposition::KEEP_IN_FUND;
    }

    public static function toSettlementOption(?string $approach): string
    {
        return match (self::normalize($approach)) {
            self::ROLL_UP => LoanExcessFundSettlementOption::ROLL_UP,
            self::SKIP_FUTURE => LoanExcessFundSettlementOption::SKIP_FUTURE,
            default => LoanExcessFundSettlementOption::KEEP_IN_FUND,
        };
    }

    public static function usesConfiguredSplit(?string $approach): bool
    {
        return LoanFundingStrategy::usesConfiguredSplit(self::toFundingStrategy($approach));
    }
}
