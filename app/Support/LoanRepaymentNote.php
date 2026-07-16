<?php

declare(strict_types=1);

namespace App\Support;

final class LoanRepaymentNote
{
    public const PREFIX = 'ff:';

    public static function fullEarlySettlement(): string
    {
        return self::PREFIX.'settlement:full';
    }

    public static function partialEarlySettlement(string $option): string
    {
        return self::PREFIX.'settlement:partial:'.$option;
    }

    public static function installment(int $installmentNumber): string
    {
        return self::PREFIX.'installment:'.$installmentNumber;
    }

    public static function isSettlement(?string $notes): bool
    {
        return str_contains((string) $notes, 'settlement:');
    }

    public static function label(?string $notes): string
    {
        if ($notes === null || $notes === '') {
            return __('Repayment');
        }

        if (str_contains($notes, 'settlement:full')) {
            return __('Full early settlement');
        }

        if (preg_match('/settlement:partial:(\w+)/', $notes, $matches) === 1) {
            return match ($matches[1]) {
                'roll_up' => __('Partial early settlement (roll-up)'),
                'skip_future' => __('Partial early settlement (skip cycles)'),
                default => __('Partial early settlement'),
            };
        }

        if (str_contains($notes, 'installment:')) {
            return __('EMI repayment');
        }

        return $notes;
    }
}
