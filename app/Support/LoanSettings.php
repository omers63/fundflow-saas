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
            'member_funding_split_pct' => 50,
            'allow_funding_strategy_member_topup' => true,
            'allow_funding_strategy_split_percentage' => true,
            'allow_excess_fund_cash_out' => true,
            'auto_allocate_loan_repayment' => false,
            'late_payment_consecutive_threshold' => 3,
            'late_payment_rolling_threshold' => 15,
            'late_payment_lookback_months' => 60,
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

    public static function guarantorTransferMissedThreshold(): int
    {
        return max(1, (int) self::get('guarantor_transfer_missed_threshold', self::defaultGraceCycles() + 1));
    }

    public static function requireGuarantorAboveFundBalance(): bool
    {
        return (bool) self::get('require_guarantor_above_fund_balance', true);
    }

    /**
     * Share of each approved loan amount funded from the member fund account (0–100).
     */
    public static function memberFundingSplitPercent(): float
    {
        $stored = self::get('member_funding_split_pct', null);

        if ($stored !== null) {
            return max(0.0, min(100.0, (float) $stored));
        }

        if ((bool) self::get('fifty_fifty_funding_split', false)) {
            return 50.0;
        }

        return 50.0;
    }

    public static function masterFundingSplitPercent(): float
    {
        return round(100.0 - self::memberFundingSplitPercent(), 2);
    }

    public static function allowMemberFundTopupStrategy(): bool
    {
        return (bool) self::get('allow_funding_strategy_member_topup', true);
    }

    public static function allowSplitPercentageStrategy(): bool
    {
        return (bool) self::get('allow_funding_strategy_split_percentage', true);
    }

    public static function allowExcessFundCashOut(): bool
    {
        return (bool) self::get('allow_excess_fund_cash_out', true);
    }

    public static function hasAvailableFundingStrategy(): bool
    {
        return self::allowMemberFundTopupStrategy() || self::allowSplitPercentageStrategy();
    }

    /**
     * Member vs. master portions of an approved loan amount for a chosen application strategy.
     *
     * @return array{member_portion: float, master_portion: float}
     */
    public static function resolveFundingPortions(
        float $loanAmount,
        float $memberFundBalance,
        ?string $fundingStrategy = null,
    ): array {
        if ($loanAmount <= 0) {
            return ['member_portion' => 0.0, 'master_portion' => 0.0];
        }

        $strategy = LoanFundingStrategy::normalize($fundingStrategy);

        if ($strategy === LoanFundingStrategy::SPLIT_PERCENTAGE) {
            $memberPortion = round($loanAmount * (self::memberFundingSplitPercent() / 100), 2);

            return [
                'member_portion' => $memberPortion,
                'master_portion' => round($loanAmount - $memberPortion, 2),
            ];
        }

        $memberPortion = round(min(max(0.0, $memberFundBalance), $loanAmount), 2);

        return [
            'member_portion' => $memberPortion,
            'master_portion' => round($loanAmount - $memberPortion, 2),
        ];
    }

    /**
     * Fund balance the member must hold to cover their share at disbursement.
     */
    public static function requiredMemberFundForLoanAmount(float $loanAmount, ?string $fundingStrategy = null): float
    {
        if ($loanAmount <= 0) {
            return 0.0;
        }

        $strategy = LoanFundingStrategy::normalize($fundingStrategy);

        if ($strategy === LoanFundingStrategy::SPLIT_PERCENTAGE) {
            return round($loanAmount * (self::memberFundingSplitPercent() / 100), 2);
        }

        return $loanAmount;
    }

    public static function excessFundCashOutAmount(
        float $loanAmount,
        float $memberFundBalance,
        ?string $fundingStrategy = null,
    ): float {
        if (LoanFundingStrategy::normalize($fundingStrategy) !== LoanFundingStrategy::SPLIT_PERCENTAGE) {
            return 0.0;
        }

        $portions = self::resolveFundingPortions($loanAmount, $memberFundBalance, $fundingStrategy);

        return round(max(0.0, $memberFundBalance - $portions['member_portion']), 2);
    }

    public static function autoAllocateLoanRepayment(): bool
    {
        return (bool) self::get('auto_allocate_loan_repayment', false);
    }

    public static function maxActiveLoans(): int
    {
        return max(1, (int) self::get('max_active_loans', 1));
    }

    public static function latePaymentConsecutiveThreshold(): int
    {
        return max(1, (int) self::get('late_payment_consecutive_threshold', 3));
    }

    public static function latePaymentRollingThreshold(): int
    {
        return max(1, (int) self::get('late_payment_rolling_threshold', 15));
    }

    public static function latePaymentLookbackMonths(): int
    {
        return max(1, (int) self::get('late_payment_lookback_months', 60));
    }

    public static function guarantorRequiredForAmount(
        Member $member,
        float $amount,
        ?string $fundingStrategy = null,
    ): bool {
        if (! self::requireGuarantorAboveFundBalance()) {
            return false;
        }

        return self::requiredMemberFundForLoanAmount($amount, $fundingStrategy) > $member->getFundBalance() + 0.01;
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
