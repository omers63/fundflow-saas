<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Loan;
use App\Support\BusinessDay;
use App\Support\LoanExcessFundSettlementOption;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * After split-with-early-settlement disbursement: move remaining fund above the member
 * share into cash, then apply partial early settlement (roll-up or skip).
 */
final class LoanSplitExcessFundEarlySettlementService
{
    public function __construct(
        private readonly LoanLedgerService $loanLedger,
        private readonly LoanEarlySettlementService $earlySettlement,
        private readonly LoanSplitExcessFundCashOutService $excessCashOut,
    ) {}

    public function applyAfterDisbursement(Loan $loan): void
    {
        if (LoanFundingStrategy::normalize($loan->funding_strategy) !== LoanFundingStrategy::SPLIT_WITH_EARLY_SETTLEMENT) {
            return;
        }

        $option = LoanExcessFundSettlementOption::toSettlementOption($loan->excess_fund_settlement_option);

        if ($option === null) {
            return;
        }

        if (! in_array($loan->status, ['active', 'early_settled', 'completed'], true)) {
            return;
        }

        $excess = $this->excessCashOut->disbursementExcessAmount($loan);

        if ($excess <= 0.00001) {
            return;
        }

        $alreadyTransferred = $this->excessCashOut->alreadyTransferredAmount($loan);
        $toTransfer = round(max(0.0, $excess - $alreadyTransferred), 2);

        try {
            DB::transaction(function () use ($loan, $toTransfer, $option, $excess): void {
                $loan = $loan->fresh() ?? $loan;

                if ($toTransfer > 0.00001) {
                    $this->loanLedger->transferMemberFundBalanceToCash(
                        $loan,
                        $toTransfer,
                        BusinessDay::now(),
                        allowNegativeMemberFundBalance: true,
                    );
                }

                $loan->loadMissing('member');
                $member = $loan->member;

                if ($member === null) {
                    return;
                }

                $member->unsetRelation('accounts');
                $cash = $member->getCashBalance();
                $settleAmount = round(min($excess, max(0.0, $cash)), 2);

                if ($settleAmount <= 0.00001) {
                    return;
                }

                $pending = $this->earlySettlement->pendingInstallments($loan);

                if ($pending->isEmpty()) {
                    return;
                }

                // Below one EMI: leave cash for the member; do not force a partial settle.
                $minEmi = (float) ($pending->first()?->amount ?? 0);

                if ($settleAmount < $minEmi - 0.00001) {
                    return;
                }

                $this->earlySettlement->settle($loan->fresh() ?? $loan, $settleAmount, $option, sendNotification: false);
            });
        } catch (Throwable $e) {
            Log::warning('Loan split excess early settlement failed', [
                'loan_id' => $loan->id,
                'option' => $option,
                'error' => $e->getMessage(),
            ]);
        }
    }

    public function estimatedExcess(Loan $loan, float $fundBalanceAtDisbursement): float
    {
        return LoanSettings::excessFundCashOutAmount(
            (float) ($loan->amount_approved ?: $loan->amount_requested ?: $loan->amount),
            $fundBalanceAtDisbursement,
            $loan->funding_strategy,
        );
    }
}
