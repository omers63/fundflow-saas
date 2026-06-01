<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\Transaction;
use App\Support\ContributionPolicySettings;

/**
 * Member-level ledger drift checks per fund_management_system_requirements.md §5.13.
 */
class MemberInvariantService
{
    /**
     * @return array{
     *     balanced: bool,
     *     fund_drift: float,
     *     cash_drift: float,
     *     expected_fund: float,
     *     expected_cash: float,
     *     actual_fund: float,
     *     actual_cash: float,
     *     components: array<string, float>
     * }
     */
    public function check(Member $member): array
    {
        $member->loadMissing(['cashAccount', 'fundAccount']);

        $openingFund = (float) ($member->opening_fund_balance ?? 0);
        $openingCash = (float) ($member->opening_cash_balance ?? 0);

        $fundAccountId = $member->fundAccount?->id;
        $cashAccountId = $member->cashAccount?->id;

        $contributionsCollected = $fundAccountId
            ? $this->sumByReference($fundAccountId, $member->id, Contribution::class, 'credit')
            : 0.0;

        $contributionFundReversals = $fundAccountId
            ? $this->sumByReference($fundAccountId, $member->id, Contribution::class, 'debit')
            : 0.0;

        $loanDisbursementsFromFund = $fundAccountId
            ? $this->sumByReference($fundAccountId, $member->id, Loan::class, 'debit')
            : 0.0;

        $guarantorFundDebits = $fundAccountId
            ? $this->sumByReference($fundAccountId, $member->id, LoanInstallment::class, 'debit')
            : 0.0;

        $emiRepayments = $fundAccountId
            ? $this->sumByReference($fundAccountId, $member->id, LoanInstallment::class, 'credit')
            : 0.0;

        $depositsReceived = $cashAccountId
            ? $this->sumByReference($cashAccountId, $member->id, FundPosting::class, 'credit')
            : 0.0;

        $subscriptionDeposits = $cashAccountId
            ? $this->sumByReference($cashAccountId, $member->id, MembershipApplication::class, 'credit')
            : 0.0;

        $loanDisbursementsCredited = $cashAccountId
            ? $this->sumByReference($cashAccountId, $member->id, Loan::class, 'credit')
            : 0.0;

        $dependentTransfersIn = $cashAccountId
            ? $this->sumDescriptionPattern($cashAccountId, $member->id, 'credit', 'Transfer from%')
            : 0.0;

        $contributionsDebited = $cashAccountId
            ? $this->sumContributionPrincipalDebited($cashAccountId, $member->id)
            : 0.0;

        $emiDebited = $cashAccountId
            ? $this->sumByReference($cashAccountId, $member->id, LoanInstallment::class, 'debit')
            : 0.0;

        $subscriptionFeesDebited = $cashAccountId
            ? $this->sumByReference($cashAccountId, $member->id, MembershipApplication::class, 'debit')
            : 0.0;

        $lateFeesNet = $cashAccountId
            ? $this->sumLateFeesNet($cashAccountId, $member->id)
            : 0.0;

        $cashOuts = $cashAccountId
            ? $this->sumCashOuts($cashAccountId, $member->id)
            : 0.0;

        $refundsAndCorrections = $cashAccountId
            ? $this->sumRefundsAndReconCredits($cashAccountId, $member->id)
            : 0.0;

        $dependentTransfersOut = $cashAccountId
            ? $this->sumDescriptionPattern($cashAccountId, $member->id, 'debit', 'Transfer to%')
            : 0.0;

        $expectedFund = $openingFund
            + $contributionsCollected
            - $contributionFundReversals
            - $loanDisbursementsFromFund
            - $guarantorFundDebits
            + $emiRepayments;

        $expectedCash = $openingCash
            + $depositsReceived
            + $subscriptionDeposits
            + $loanDisbursementsCredited
            + $dependentTransfersIn
            + $refundsAndCorrections
            - $contributionsDebited
            - $emiDebited
            - $subscriptionFeesDebited
            - $lateFeesNet
            - $cashOuts
            - $dependentTransfersOut;

        $actualFund = (float) ($member->fundAccount?->balance ?? 0);
        $actualCash = (float) ($member->cashAccount?->balance ?? 0);

        $fundDrift = abs($expectedFund - $actualFund);
        $cashDrift = abs($expectedCash - $actualCash);
        $tolerance = ContributionPolicySettings::reconTolerance();

        return [
            'balanced' => $fundDrift <= $tolerance && $cashDrift <= $tolerance,
            'fund_drift' => $fundDrift,
            'cash_drift' => $cashDrift,
            'expected_fund' => $expectedFund,
            'expected_cash' => $expectedCash,
            'actual_fund' => $actualFund,
            'actual_cash' => $actualCash,
            'components' => [
                'opening_fund' => $openingFund,
                'contributions_collected' => $contributionsCollected,
                'contribution_fund_reversals' => $contributionFundReversals,
                'loan_disbursements_from_fund' => $loanDisbursementsFromFund,
                'guarantor_fund_debits' => $guarantorFundDebits,
                'emi_repayments' => $emiRepayments,
                'opening_cash' => $openingCash,
                'deposits_received' => $depositsReceived,
                'subscription_deposits' => $subscriptionDeposits,
                'loan_disbursements_credited' => $loanDisbursementsCredited,
                'dependent_transfers_in' => $dependentTransfersIn,
                'refunds_and_recon_credits' => $refundsAndCorrections,
                'contributions_debited' => $contributionsDebited,
                'emi_debited' => $emiDebited,
                'subscription_fees_debited' => $subscriptionFeesDebited,
                'late_fees_net' => $lateFeesNet,
                'cash_outs' => $cashOuts,
                'dependent_transfers_out' => $dependentTransfersOut,
            ],
        ];
    }

    protected function sumByReference(
        int $accountId,
        int $memberId,
        string $referenceClass,
        string $type,
    ): float {
        return (float) Transaction::query()
            ->where('account_id', $accountId)
            ->where('member_id', $memberId)
            ->where('type', $type)
            ->where('reference_type', (new $referenceClass)->getMorphClass())
            ->sum('amount');
    }

    protected function sumContributionPrincipalDebited(int $accountId, int $memberId): float
    {
        return (float) Transaction::query()
            ->where('account_id', $accountId)
            ->where('member_id', $memberId)
            ->where('type', 'debit')
            ->where('reference_type', (new Contribution)->getMorphClass())
            ->where(function ($query): void {
                $query->where('description', 'not like', '%late fee%')
                    ->where('description', 'not like', '%Late fee%');
            })
            ->sum('amount');
    }

    protected function sumDescriptionPattern(
        int $accountId,
        int $memberId,
        string $type,
        string $descriptionPattern,
    ): float {
        return (float) Transaction::query()
            ->where('account_id', $accountId)
            ->where('member_id', $memberId)
            ->where('type', $type)
            ->where('description', 'like', $descriptionPattern)
            ->sum('amount');
    }

    /**
     * Net late-fee cash impact (debits for fees posted minus credits for reversals).
     */
    protected function sumLateFeesNet(int $accountId, int $memberId): float
    {
        $query = Transaction::query()
            ->where('account_id', $accountId)
            ->where('member_id', $memberId)
            ->where(function ($builder): void {
                $builder->where('description', 'like', '%late fee%')
                    ->orWhere('description', 'like', '%Late fee%');
            });

        $debited = (float) (clone $query)->where('type', 'debit')->sum('amount');
        $credited = (float) (clone $query)->where('type', 'credit')->sum('amount');

        return $debited - $credited;
    }

    protected function sumRefundsAndReconCredits(int $accountId, int $memberId): float
    {
        $refunds = (float) Transaction::query()
            ->where('account_id', $accountId)
            ->where('member_id', $memberId)
            ->where('type', 'credit')
            ->where(function ($query): void {
                $query->where('description', 'like', 'Refund —%')
                    ->orWhere('description', 'like', '%RECON_%REFUND%')
                    ->orWhere('description', 'like', '%RECON_EMI_OVERPAYMENT_REFUND%');
            })
            ->sum('amount');

        $reconCredits = $this->sumByReference($accountId, $memberId, ReconciliationException::class, 'credit');

        return $refunds + $reconCredits;
    }

    protected function sumCashOuts(int $accountId, int $memberId): float
    {
        $fromRequests = $this->sumByReference($accountId, $memberId, CashOutRequest::class, 'debit');

        $legacy = (float) Transaction::query()
            ->where('account_id', $accountId)
            ->where('member_id', $memberId)
            ->where('type', 'debit')
            ->where(function ($query): void {
                $query->where('description', 'like', '%refund%')
                    ->orWhere('description', 'like', '%Refund%')
                    ->orWhere('description', 'like', '%(cash out)%')
                    ->orWhere('description', 'like', '%(cash clearing to master cash)%');
            })
            ->sum('amount');

        return $fromRequests + $legacy;
    }
}
