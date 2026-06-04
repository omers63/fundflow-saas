<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use App\Models\Tenant\Member;
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
     *     total_repay: float
     * }>
     */
    public function calculationsForAmount(float $loanAmount, Member $member): array
    {
        if ($loanAmount <= 0) {
            return [];
        }

        $fundBalance = $member->getFundBalance();
        $settlementPct = LoanSettings::settlementThreshold();
        $results = [];

        foreach ($this->activeTiers() as $tier) {
            if ($loanAmount < (float) $tier->min_amount || $loanAmount > (float) $tier->max_amount) {
                continue;
            }

            $memberPortion = min(max(0.0, $fundBalance), $loanAmount);
            $masterPortion = $loanAmount - $memberPortion;
            $minInstallment = (float) $tier->min_monthly_installment;
            $installments = Loan::computeInstallmentsCount(
                $loanAmount,
                $fundBalance,
                $minInstallment,
                $settlementPct,
            );
            $settlementAmt = $loanAmount * $settlementPct;
            $totalToRepay = $masterPortion + $settlementAmt;

            $results[] = [
                'tier' => $tier,
                'min_installment' => $minInstallment,
                'installments' => $installments,
                'member_portion' => $memberPortion,
                'master_portion' => $masterPortion,
                'settlement_amt' => $settlementAmt,
                'total_repay' => $totalToRepay,
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
