<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use App\Services\ContributionCycleService;
use App\Services\MemberLatePaymentHistoryEvaluator;
use App\Support\BusinessDay;
use App\Support\ContributionCollectionStatus;
use App\Support\ContributionPolicySettings;
use App\Support\LoanEligibilityGate;
use App\Support\LoanSettings;
use Carbon\CarbonInterface;

class LoanEligibilityService
{
    public function __construct(
        protected ContributionCycleService $cycles,
        protected LoanDelinquencyService $delinquency,
        protected MemberLatePaymentHistoryEvaluator $latePaymentHistory,
        protected LoanEligibilityOverrideService $overrides,
    ) {}

    public function isEligible(Member $member, ?array $skipGates = null): bool
    {
        return $this->getIneligibilityReason($member, $skipGates) === '';
    }

    public function getIneligibilityReason(Member $member, ?array $skipGates = null): string
    {
        $failed = $this->getFailedGates($member, $skipGates);

        return $failed !== [] ? reset($failed) : '';
    }

    /**
     * @param  list<string>|null  $skipGates
     * @return array<string, string> gate => reason
     */
    public function getFailedGates(Member $member, ?array $skipGates = null): array
    {
        $skip = array_flip(array_merge(
            $this->overrides->overriddenGatesFor($member),
            $skipGates ?? [],
        ));

        $failed = [];

        if (! isset($skip[LoanEligibilityGate::MEMBERSHIP_STATUS]) && ! $member->isActive()) {
            $failed[LoanEligibilityGate::MEMBERSHIP_STATUS] = __('Your membership status is not active.');
        }

        if (! isset($skip[LoanEligibilityGate::ACTIVE_LOAN])) {
            $maxActive = LoanSettings::maxActiveLoans();
            $activeLoanCount = Loan::query()
                ->where('member_id', $member->id)
                ->whereIn('status', ['pending', 'approved', 'partially_disbursed', 'active', 'transferred'])
                ->count();

            if ($activeLoanCount >= $maxActive) {
                $failed[LoanEligibilityGate::ACTIVE_LOAN] = __('You already have :count loan(s) in progress (maximum :max). Cancel a pending loan or fully settle an active loan before applying again.', [
                    'count' => $activeLoanCount,
                    'max' => $maxActive,
                ]);
            }
        }

        if (! isset($skip[LoanEligibilityGate::MEMBERSHIP_TENURE])) {
            $tenure = $this->membershipTenureStatus($member);

            if ($tenure['blocked']) {
                $failed[LoanEligibilityGate::MEMBERSHIP_TENURE] = $tenure['message'];
            }
        }

        if (! isset($skip[LoanEligibilityGate::SETTLEMENT_COOLDOWN])) {
            $cooldown = $this->settlementCooldownStatus($member);

            if ($cooldown['blocked']) {
                $failed[LoanEligibilityGate::SETTLEMENT_COOLDOWN] = $cooldown['message'];
            }
        }

        if (! isset($skip[LoanEligibilityGate::MIN_FUND_BALANCE])) {
            $minFund = LoanSettings::minFundBalance();
            $fundBal = (float) ($member->fundAccount?->balance ?? 0);
            if ($fundBal < $minFund) {
                $failed[LoanEligibilityGate::MIN_FUND_BALANCE] = __('Your fund account balance (:balance) must be at least :minimum to be eligible.', [
                    'balance' => number_format($fundBal, 2),
                    'minimum' => number_format($minFund, 2),
                ]);
            }
        }

        if (! isset($skip[LoanEligibilityGate::DELINQUENCY])) {
            if ($this->hasPendingContributionCollections($member)) {
                $failed[LoanEligibilityGate::DELINQUENCY] = __('You have an unsettled contribution collection from a previous cycle. Clear it before applying for a loan.');
            } elseif ($this->delinquency->memberHasArrearsExcludingOpenCycle($member)) {
                $failed[LoanEligibilityGate::DELINQUENCY] = __('You have outstanding contribution arrears or overdue loan installments. Clear them before applying for a loan.');
            } else {
                $lateHistory = $this->latePaymentHistory->evaluate($member);

                if ($this->latePaymentHistory->shouldBlockFromLateContributions($lateHistory['contribution'])) {
                    $contribution = $lateHistory['contribution'];

                    if ($contribution['trailing_consecutive'] >= ContributionPolicySettings::lateSettlementConsecutiveThreshold()) {
                        $failed[LoanEligibilityGate::DELINQUENCY] = __('You have :count consecutive late contribution cycles (limit :limit).', [
                            'count' => $contribution['trailing_consecutive'],
                            'limit' => ContributionPolicySettings::lateSettlementConsecutiveThreshold(),
                        ]);
                    } else {
                        $failed[LoanEligibilityGate::DELINQUENCY] = __('You have :count late contribution cycles in the last :months months (limit :limit).', [
                            'count' => $contribution['rolling_total'],
                            'months' => ContributionPolicySettings::lateSettlementLookbackMonths(),
                            'limit' => ContributionPolicySettings::lateSettlementRollingThreshold(),
                        ]);
                    }
                } elseif ($this->latePaymentHistory->shouldBlockFromLateRepayments($lateHistory['repayment'])) {
                    $repayment = $lateHistory['repayment'];

                    if ($repayment['trailing_consecutive'] >= LoanSettings::latePaymentConsecutiveThreshold()) {
                        $failed[LoanEligibilityGate::DELINQUENCY] = __('You have :count consecutive late EMI cycles (limit :limit).', [
                            'count' => $repayment['trailing_consecutive'],
                            'limit' => LoanSettings::latePaymentConsecutiveThreshold(),
                        ]);
                    } else {
                        $failed[LoanEligibilityGate::DELINQUENCY] = __('You have :count late EMI cycles in the last :months months (limit :limit).', [
                            'count' => $repayment['rolling_total'],
                            'months' => LoanSettings::latePaymentLookbackMonths(),
                            'limit' => LoanSettings::latePaymentRollingThreshold(),
                        ]);
                    }
                }
            }
        }

        return $failed;
    }

    public function hasPendingContributionCollections(Member $member): bool
    {
        [$openMonth, $openYear] = $this->cycles->currentOpenPeriod();
        $openPeriod = Contribution::periodDate($openMonth, $openYear);

        return Contribution::query()
            ->where('member_id', $member->id)
            ->where('period', '!=', $openPeriod)
            ->whereIn('collection_status', ContributionCollectionStatus::openCollectionStates())
            ->exists();
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
            'eligible_from' => $this->nextEligibilityDate($member)?->format('d M Y') ?? '—',
        ];
    }

    public function nextEligibilityDate(Member $member): ?CarbonInterface
    {
        $candidates = collect([
            $this->membershipTenureStatus($member)['eligible_from'],
            $this->settlementCooldownStatus($member)['eligible_from'],
        ])->filter();

        if ($candidates->isEmpty()) {
            return null;
        }

        return $candidates
            ->sortByDesc(fn (CarbonInterface $date): int => $date->getTimestamp())
            ->first();
    }

    /**
     * @return array{blocked: bool, eligible_from: ?CarbonInterface, message: string}
     */
    public function membershipTenureStatus(Member $member): array
    {
        $requiredMonths = LoanSettings::eligibilityMonths();
        $membershipStart = $member->loanEligibilityStartDate();

        if ($membershipStart === null) {
            return [
                'blocked' => true,
                'eligible_from' => null,
                'message' => __('Membership start date is not set. Set membership date on the member or application.'),
            ];
        }

        $eligibleFrom = $membershipStart->copy()->addMonths($requiredMonths);

        if ($eligibleFrom->isAfter(BusinessDay::today())) {
            return [
                'blocked' => true,
                'eligible_from' => $eligibleFrom,
                'message' => __('You are not yet eligible for a loan. Eligibility starts on :date (:months months from membership start).', [
                    'date' => $eligibleFrom->format('d M Y'),
                    'months' => $requiredMonths,
                ]),
            ];
        }

        return [
            'blocked' => false,
            'eligible_from' => null,
            'message' => '',
        ];
    }

    /**
     * @return array{blocked: bool, eligible_from: ?CarbonInterface, message: string, loan: ?Loan, required_balance: float, current_balance: float}
     */
    public function settlementCooldownStatus(Member $member): array
    {
        $loan = $member->lastFullySettledLoan();

        if (! $loan instanceof Loan) {
            return [
                'blocked' => false,
                'eligible_from' => null,
                'message' => '',
                'loan' => null,
                'required_balance' => 0.0,
                'current_balance' => (float) ($member->fundAccount?->balance ?? 0),
            ];
        }

        $requiredBalance = $loan->eligibilityThresholdAmount();
        $currentBalance = (float) ($member->fundAccount?->balance ?? 0);

        if ($requiredBalance <= 0.00001 || $currentBalance + 0.00001 >= $requiredBalance) {
            return [
                'blocked' => false,
                'eligible_from' => null,
                'message' => '',
                'loan' => $loan,
                'required_balance' => round($requiredBalance, 2),
                'current_balance' => round($currentBalance, 2),
            ];
        }

        return [
            'blocked' => true,
            'eligible_from' => null,
            'message' => __('You fully settled loan #:id, but your fund balance (:balance) must reach :required before you can apply again. This eligibility threshold is based on the last loan tier ceiling (:base).', [
                'id' => $loan->id,
                'balance' => number_format($currentBalance, 2),
                'required' => number_format($requiredBalance, 2),
                'base' => number_format($loan->eligibilityThresholdBaseAmount(), 2),
            ]),
            'loan' => $loan,
            'required_balance' => round($requiredBalance, 2),
            'current_balance' => round($currentBalance, 2),
        ];
    }
}
