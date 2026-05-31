<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
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

        $migrationInstalmentsCollected = $fundAccountId
            ? $this->sumMigrationCollectedOnFund($fundAccountId, $member->id)
            : 0.0;

        $loanDisbursementsMemberPortion = $fundAccountId
            ? $this->sumLoanDisbursementMemberMirror($fundAccountId, $member->id)
            : 0.0;

        $emiRepayments = $fundAccountId
            ? $this->sumByReference($fundAccountId, $member->id, LoanInstallment::class, 'credit')
            : 0.0;

        $depositsReceived = $cashAccountId
            ? $this->sumByReference($cashAccountId, $member->id, FundPosting::class, 'credit')
            : 0.0;

        $loanDisbursementsCredited = $cashAccountId
            ? $this->sumLoanCashPayouts($cashAccountId, $member->id)
            : 0.0;

        $contributionsDebited = $cashAccountId
            ? $this->sumByReference($cashAccountId, $member->id, Contribution::class, 'debit')
            : 0.0;

        $emiDebited = $cashAccountId
            ? $this->sumByReference($cashAccountId, $member->id, LoanInstallment::class, 'debit')
            : 0.0;

        $lateFeesDebited = $cashAccountId
            ? $this->sumLateFeesDebited($cashAccountId, $member->id)
            : 0.0;

        $cashOuts = $cashAccountId
            ? $this->sumCashOuts($cashAccountId, $member->id)
            : 0.0;

        $expectedFund = $openingFund
            + $contributionsCollected
            + $migrationInstalmentsCollected
            - $loanDisbursementsMemberPortion
            + $emiRepayments;

        $expectedCash = $openingCash
            + $depositsReceived
            + $loanDisbursementsCredited
            - $contributionsDebited
            - $emiDebited
            - $lateFeesDebited
            - $cashOuts;

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
                'migration_instalments_collected' => $migrationInstalmentsCollected,
                'loan_disbursements_member_portion' => $loanDisbursementsMemberPortion,
                'emi_repayments' => $emiRepayments,
                'opening_cash' => $openingCash,
                'deposits_received' => $depositsReceived,
                'loan_disbursements_credited' => $loanDisbursementsCredited,
                'contributions_debited' => $contributionsDebited,
                'emi_debited' => $emiDebited,
                'late_fees_debited' => $lateFeesDebited,
                'cash_outs' => $cashOuts,
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

    protected function sumMigrationCollectedOnFund(int $accountId, int $memberId): float
    {
        return (float) Transaction::query()
            ->where('account_id', $accountId)
            ->where('member_id', $memberId)
            ->where('type', 'credit')
            ->where('description', 'like', 'MIGRATION_%')
            ->where('description', 'not like', '%MIGRATION_OPENING%')
            ->where('description', 'not like', '%MIGRATION_OB_OFFSET%')
            ->sum('amount');
    }

    protected function sumLoanDisbursementMemberMirror(int $accountId, int $memberId): float
    {
        return (float) Transaction::query()
            ->where('account_id', $accountId)
            ->where('member_id', $memberId)
            ->where('type', 'debit')
            ->where('reference_type', (new Loan)->getMorphClass())
            ->where('description', 'like', '%(member mirror)%')
            ->sum('amount');
    }

    protected function sumLoanCashPayouts(int $accountId, int $memberId): float
    {
        return (float) Transaction::query()
            ->where('account_id', $accountId)
            ->where('member_id', $memberId)
            ->where('type', 'credit')
            ->where('reference_type', (new Loan)->getMorphClass())
            ->where('description', 'like', '%(cash payout)%')
            ->sum('amount');
    }

    protected function sumLateFeesDebited(int $accountId, int $memberId): float
    {
        return (float) Transaction::query()
            ->where('account_id', $accountId)
            ->where('member_id', $memberId)
            ->where('type', 'debit')
            ->where(function ($query): void {
                $query->where('description', 'like', '%late fee%')
                    ->orWhere('description', 'like', '%Late fee%');
            })
            ->sum('amount');
    }

    protected function sumCashOuts(int $accountId, int $memberId): float
    {
        $fromRequests = $this->sumByReference($accountId, $memberId, CashOutRequest::class, 'debit');

        $legacy = (float) Transaction::query()
            ->where('account_id', $accountId)
            ->where('member_id', $memberId)
            ->where('type', 'debit')
            ->where(function ($query): void {
                $query->where('description', 'like', 'MIGRATION_LUMPSUM%')
                    ->orWhere('description', 'like', '%refund%')
                    ->orWhere('description', 'like', '%Refund%')
                    ->orWhere('description', 'like', '%(cash out)%')
                    ->orWhere('description', 'like', '%(cash clearing to master cash)%');
            })
            ->sum('amount');

        return $fromRequests + $legacy;
    }
}
