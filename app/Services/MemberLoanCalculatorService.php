<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
use App\Support\LoanExcessFundSettlementOption;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use Illuminate\Database\Eloquent\Collection;

final class MemberLoanCalculatorService
{
    /**
     * @return list<array{
     *     tier: LoanTier,
     *     min_installment: float,
     *     installments: int,
     *     member_portion: float,
     *     master_portion: float,
     *     settlement_amt: float,
     *     total_repay: float,
     *     excess_fund: float,
     *     early_settlement_amount: float,
     *     installments_covered: int,
     *     remaining_payment_months: int|null
     * }>
     */
    public function calculationsForAmount(
        float $loanAmount,
        Member $member,
        ?string $fundingStrategy = null,
        ?string $excessFundSettlementOption = null,
    ): array {
        if ($loanAmount <= 0) {
            return [];
        }

        $fundBalance = $member->getFundBalance();
        $settlementPct = LoanSettings::settlementThreshold();
        $strategy = LoanFundingStrategy::normalize($fundingStrategy);
        $settlementChoice = LoanExcessFundSettlementOption::normalize($excessFundSettlementOption);
        $results = [];

        foreach ($this->activeTiers() as $tier) {
            if ($loanAmount < (float) $tier->min_amount || $loanAmount > (float) $tier->max_amount) {
                continue;
            }

            $portions = LoanSettings::resolveFundingPortions($loanAmount, $fundBalance, $strategy);
            $memberPortion = $portions['member_portion'];
            $masterPortion = $portions['master_portion'];
            $minInstallment = (float) $tier->min_monthly_installment;
            $installments = Loan::computeInstallmentsCount(
                $loanAmount,
                $fundBalance,
                $minInstallment,
                $settlementPct,
                $strategy,
            );
            $settlementAmt = $loanAmount * $settlementPct;
            $totalToRepay = $masterPortion + $settlementAmt;
            $excessFund = LoanSettings::excessFundCashOutAmount($loanAmount, $fundBalance, $strategy);

            $earlySettlementAmount = 0.0;
            $installmentsCovered = 0;
            $remainingPaymentMonths = null;

            if (
                $strategy === LoanFundingStrategy::SPLIT_WITH_EARLY_SETTLEMENT
                && LoanExcessFundSettlementOption::appliesAsEarlySettlement($settlementChoice)
                && $excessFund > 0.00001
                && $minInstallment > 0.00001
            ) {
                $earlySettlementAmount = $excessFund;
                $installmentsCovered = (int) floor($excessFund / $minInstallment);

                if ($installmentsCovered > 0) {
                    $remainingPaymentMonths = match ($settlementChoice) {
                        LoanExcessFundSettlementOption::ROLL_UP => max(0, $installments - (2 * $installmentsCovered)),
                        LoanExcessFundSettlementOption::SKIP_FUTURE => max(0, $installments - $installmentsCovered),
                        default => null,
                    };
                }
            }

            $results[] = [
                'tier' => $tier,
                'min_installment' => $minInstallment,
                'installments' => $installments,
                'member_portion' => $memberPortion,
                'master_portion' => $masterPortion,
                'settlement_amt' => $settlementAmt,
                'total_repay' => $totalToRepay,
                'excess_fund' => $excessFund,
                'early_settlement_amount' => $earlySettlementAmount,
                'installments_covered' => $installmentsCovered,
                'remaining_payment_months' => $remainingPaymentMonths,
            ];
        }

        return $results;
    }

    /**
     * @return Collection<int, LoanTier>
     */
    public function activeTiers(): Collection
    {
        return LoanTier::query()
            ->where('is_active', true)
            ->orderBy('min_amount')
            ->get();
    }

    public function settlementThresholdPercent(): float
    {
        return LoanSettings::settlementThreshold();
    }
}
