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

    public static function installment(int $installmentNumber, bool $paidByGuarantor = false): string
    {
        $base = self::PREFIX.'installment:'.$installmentNumber;

        return $paidByGuarantor ? $base.':guarantor' : $base;
    }

    public static function isSettlement(?string $notes): bool
    {
        return str_contains((string) $notes, 'settlement:');
    }

    public static function isGuarantorPaid(?string $notes): bool
    {
        return str_contains((string) $notes, ':guarantor')
            || str_contains((string) $notes, 'guarantor_installment:');
    }

    public static function installmentNumber(?string $notes): ?int
    {
        if (preg_match('/installment:(\d+)/', (string) $notes, $matches) !== 1) {
            return null;
        }

        return (int) $matches[1];
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

        if (self::isGuarantorPaid($notes)) {
            return __('Guarantor paid');
        }

        if (str_contains($notes, 'installment:')) {
            return __('EMI repayment');
        }

        return $notes;
    }

    public static function badgeColor(?string $notes): string
    {
        if (self::isSettlement($notes)) {
            return 'success';
        }

        if (self::isGuarantorPaid($notes)) {
            return 'warning';
        }

        return 'gray';
    }
}
