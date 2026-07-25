<?php

declare(strict_types=1);

namespace App\Services\Loans;

/**
 * Preview amounts shown before an admin loan transfer is confirmed.
 */
final class LoanTransferPreview
{
    public const MODE_REMAINING = 'remaining';

    public const MODE_FULL = 'full';

    /**
     * @param  array{member_portion: float, master_portion: float}  $redisbursePortions
     */
    public function __construct(
        public readonly string $mode,
        public readonly float $approvedAmount,
        public readonly float $memberPortion,
        public readonly float $masterPortion,
        public readonly float $repaidToMaster,
        public readonly float $remainingObligation,
        public readonly float $loanAccountBalance,
        public readonly float $memberFundNetChange,
        public readonly float $fundRestoreAmount,
        public readonly float $directMasterReverseAmount,
        public readonly float $recipientFundBalance,
        public readonly float $requiredRecipientFund,
        public readonly array $redisbursePortions,
        public readonly bool $recipientFundSufficient,
        public readonly bool $recipientAtActiveLoanCap,
        public readonly int $recipientActiveLoanCount,
        public readonly int $maxActiveLoans,
        public readonly float $masterFundBalance,
        public readonly bool $masterFundSufficientForRedisburse,
    ) {}

    public static function remainingObligation(float $masterPortion, float $repaidToMaster): float
    {
        return max(0.0, round($masterPortion - $repaidToMaster, 2));
    }

    /**
     * Amount to credit (positive) or debit (negative) on the borrower's fund to reverse this loan's fund effect.
     */
    public static function fundRestoreAmount(float $memberFundNetChange): float
    {
        return round(-1 * $memberFundNetChange, 2);
    }
}
