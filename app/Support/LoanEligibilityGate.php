<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Loan eligibility gates that may be bypassed by an admin override.
 */
final class LoanEligibilityGate
{
    public const MEMBERSHIP_STATUS = 'membership_status';

    public const ACTIVE_LOAN = 'active_loan';

    public const MEMBERSHIP_TENURE = 'membership_tenure';

    public const MIN_FUND_BALANCE = 'min_fund_balance';

    public const DELINQUENCY = 'delinquency';

    public const SETTLEMENT_COOLDOWN = 'settlement_cooldown';

    public const OTHER = 'other';

    /**
     * @return array<string, string>
     */
    public static function labels(): array
    {
        return [
            self::MEMBERSHIP_STATUS => __('Membership status'),
            self::ACTIVE_LOAN => __('Active loan limit'),
            self::MEMBERSHIP_TENURE => __('Membership tenure'),
            self::MIN_FUND_BALANCE => __('Minimum fund balance'),
            self::DELINQUENCY => __('Delinquency / payment history'),
            self::SETTLEMENT_COOLDOWN => __('Post-settlement waiting period'),
            self::OTHER => __('Other'),
        ];
    }
}
