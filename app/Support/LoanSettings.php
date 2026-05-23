<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;

final class LoanSettings
{
    public const GROUP = 'loan';

    /**
     * @return array<string, mixed>
     */
    public static function defaults(): array
    {
        return [
            'eligibility_months' => 12,
            'min_fund_balance' => 6000,
            'max_borrow_multiplier' => 2,
            'default_interest_rate' => 10,
            'default_term_months' => 12,
            'max_loan_amount' => 0,
            'settlement_threshold_pct' => 0.16,
            'default_grace_cycles' => 2,
            'guarantor_transfer_missed_threshold' => 3,
            'max_active_loans' => 1,
            'require_guarantor_above_fund_balance' => true,
            'auto_allocate_loan_repayment' => false,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function all(): array
    {
        return array_merge(self::defaults(), Setting::getGroup(self::GROUP));
    }

    public static function eligibilityMonths(): int
    {
        return (int) self::get('eligibility_months', 12);
    }

    public static function minFundBalance(): float
    {
        return (float) self::get('min_fund_balance', 6000);
    }

    public static function maxBorrowMultiplier(): float
    {
        return (float) self::get('max_borrow_multiplier', 2);
    }

    public static function defaultInterestRate(): float
    {
        return (float) self::get('default_interest_rate', 10);
    }

    public static function defaultTermMonths(): int
    {
        return (int) self::get('default_term_months', 12);
    }

    public static function maxLoanAmount(): float
    {
        return (float) self::get('max_loan_amount', 0);
    }

    public static function settlementThreshold(): float
    {
        return (float) self::get('settlement_threshold_pct', 0.16);
    }

    public static function defaultGraceCycles(): int
    {
        return (int) self::get('default_grace_cycles', 2);
    }

    public static function requireGuarantorAboveFundBalance(): bool
    {
        return (bool) self::get('require_guarantor_above_fund_balance', true);
    }

    public static function autoAllocateLoanRepayment(): bool
    {
        return (bool) self::get('auto_allocate_loan_repayment', false);
    }

    public static function maxActiveLoans(): int
    {
        return max(1, (int) self::get('max_active_loans', 1));
    }

    public static function guarantorRequiredForAmount(Member $member, float $amount): bool
    {
        if (! self::requireGuarantorAboveFundBalance()) {
            return false;
        }

        return $amount > $member->getFundBalance() + 0.01;
    }

    /**
     * @param  array<string, mixed>  $values
     */
    public static function save(array $values): void
    {
        foreach (self::defaults() as $key => $default) {
            if (! array_key_exists($key, $values)) {
                continue;
            }

            Setting::set(self::GROUP, $key, $values[$key]);
        }
    }

    public static function maxLoanAmountForMember(float $fundBalance): float
    {
        $configuredMax = self::maxLoanAmount();
        $multiplierMax = $fundBalance * self::maxBorrowMultiplier();

        if ($configuredMax > 0) {
            return min($configuredMax, $multiplierMax);
        }

        return $multiplierMax;
    }

    private static function get(string $key, mixed $default): mixed
    {
        $value = Setting::get(self::GROUP, $key);

        return $value !== null ? $value : $default;
    }
}
