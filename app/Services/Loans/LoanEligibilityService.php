<?php

namespace App\Services\Loans;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Support\LoanSettings;

class LoanEligibilityService
{
    public function isEligible(Member $member): bool
    {
        return empty($this->getIneligibilityReason($member));
    }

    public function getIneligibilityReason(Member $member): string
    {
        if (! $member->isActive()) {
            return 'Your membership status is not active.';
        }

        // A member may not start a second loan request while another one is in-flight
        // (pending review or already active and not fully paid).
        $hasPendingOrActiveLoan = Loan::query()
            ->where('member_id', $member->id)
            ->whereIn('status', ['pending', 'approved', 'active'])
            ->exists();
        if ($hasPendingOrActiveLoan) {
            return 'You already have a pending or active loan. Cancel the pending loan or fully settle the active loan before applying again.';
        }

        $start = $member->loanEligibilityStartDate();
        if ($start === null) {
            return 'Membership start date is not set. Set membership date on the member or application.';
        }

        // Must have been a member for the required number of months (non-mutating date math)
        $requiredMonths = LoanSettings::eligibilityMonths();
        $eligibleFrom = $start->copy()->addMonths($requiredMonths);
        if ($eligibleFrom->isFuture()) {
            $since = $eligibleFrom->format('d M Y');

            return "You are not yet eligible for a loan. Eligibility starts on {$since} "
                ."({$requiredMonths} months from membership start).";
        }

        // Fund account must meet the minimum balance
        $minFund = LoanSettings::minFundBalance();
        $fundBal = (float) ($member->fundAccount?->balance ?? 0);
        if ($fundBal < $minFund) {
            return 'Your fund account balance (SAR '.number_format($fundBal, 2).') '
                .'must be at least SAR '.number_format($minFund).' to be eligible.';
        }

        // No overdue installments on any loan
        $hasOverdue = LoanInstallment::whereHas('loan', fn ($q) => $q->where('member_id', $member->id))
            ->where('status', 'overdue')
            ->exists();
        if ($hasOverdue) {
            return 'You have overdue loan installments. Please clear all overdue payments first.';
        }

        return '';
    }

    /**
     * Maximum loan amount the member is allowed to request.
     * = min( 2 × fund_balance , tier.max_amount for the highest eligible tier )
     */
    public function maxLoanAmount(Member $member): float
    {
        $fundBal = (float) ($member->fundAccount?->balance ?? 0);
        $multiplier = LoanSettings::maxBorrowMultiplier();

        return max(0.0, $fundBal * $multiplier);
    }

    /**
     * Resolve the loan tier for a given amount. Returns null if out of all tier ranges.
     */
    public function tierForAmount(float $amount): ?LoanTier
    {
        return LoanTier::forAmount($amount);
    }

    /**
     * Return an array of eligibility context data useful for display in the UI.
     */
    public function context(Member $member): array
    {
        $fundBal = (float) ($member->fundAccount?->balance ?? 0);
        $maxAmount = $this->maxLoanAmount($member);
        $eligible = $this->isEligible($member);
        $reason = $eligible ? '' : $this->getIneligibilityReason($member);

        return [
            'eligible' => $eligible,
            'reason' => $reason,
            'fund_balance' => $fundBal,
            'max_loan_amount' => $maxAmount,
            'min_fund_balance' => Setting::loanMinFundBalance(),
            'eligible_from' => $member->loanEligibilityStartDate()
                ?->copy()
                ->addMonths(Setting::loanEligibilityMonths())
                ->format('d M Y') ?? '—',
        ];
    }
}
