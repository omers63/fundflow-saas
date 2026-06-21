<?php

declare(strict_types=1);

namespace App\Support;

final class LoanFundExcessDisposition
{
    public const KEEP_IN_FUND = 'keep_in_fund';

    public const CASH_OUT = 'cash_out';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::KEEP_IN_FUND => __('Keep remaining balance in my fund account'),
            self::CASH_OUT => __('Transfer excess to my cash account at disbursement'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function availableOptions(): array
    {
        $options = [
            self::KEEP_IN_FUND => self::options()[self::KEEP_IN_FUND],
        ];

        if (LoanSettings::allowExcessFundCashOut()) {
            $options[self::CASH_OUT] = self::options()[self::CASH_OUT];
        }

        return $options;
    }

    public static function defaultForApplication(): string
    {
        return self::KEEP_IN_FUND;
    }

    public static function isValid(?string $disposition): bool
    {
        return in_array($disposition, [self::KEEP_IN_FUND, self::CASH_OUT], true);
    }

    public static function normalize(?string $disposition): string
    {
        if ($disposition === self::CASH_OUT && LoanSettings::allowExcessFundCashOut()) {
            return self::CASH_OUT;
        }

        return self::KEEP_IN_FUND;
    }

    public static function toCashOutFlag(?string $disposition): bool
    {
        return self::normalize($disposition) === self::CASH_OUT;
    }

    public static function fromCashOutFlag(bool $cashOut): string
    {
        return $cashOut ? self::CASH_OUT : self::KEEP_IN_FUND;
    }

    public static function label(?string $disposition): string
    {
        $normalized = self::normalize($disposition);

        return self::options()[$normalized] ?? self::options()[self::KEEP_IN_FUND];
    }

    public static function labelFromCashOutFlag(bool $cashOut): string
    {
        return self::label(self::fromCashOutFlag($cashOut));
    }
}
