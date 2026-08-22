<?php

declare(strict_types=1);

namespace App\Support;

/**
 * How remaining unpaid installments on the member's current loan are
 * applied when projecting fund at the calculator start date.
 */
final class LoanCalculatorCurrentLoanSettlement
{
    public const REGULAR_PAYMENTS = 'regular_payments';

    public const PARTIAL_TO_MATURITY = 'partial_to_maturity';

    public const FULL_EARLY_SETTLEMENT = 'full_early_settlement';

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return [
            self::REGULAR_PAYMENTS => __('Regular payments (one installment per cycle)'),
            self::PARTIAL_TO_MATURITY => __('Partial early settlement to full loan maturity'),
            self::FULL_EARLY_SETTLEMENT => __('Full early settlement (restore pre-loan fund)'),
        ];
    }

    public static function isValid(?string $mode): bool
    {
        return in_array($mode, [
            self::REGULAR_PAYMENTS,
            self::PARTIAL_TO_MATURITY,
            self::FULL_EARLY_SETTLEMENT,
        ], true);
    }

    public static function normalize(?string $mode): string
    {
        return self::isValid($mode) ? (string) $mode : self::REGULAR_PAYMENTS;
    }

    public static function isPartialToMaturity(?string $mode): bool
    {
        return self::normalize($mode) === self::PARTIAL_TO_MATURITY;
    }

    public static function isFullEarlySettlement(?string $mode): bool
    {
        return self::normalize($mode) === self::FULL_EARLY_SETTLEMENT;
    }
}
