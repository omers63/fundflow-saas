<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Loan;
use App\Models\Tenant\Transaction;
use App\Services\MemberCashOutService;
use App\Support\BusinessDay;
use App\Support\LoanFundingStrategy;
use App\Support\LoanSettings;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

final class LoanSplitExcessFundCashOutService
{
    public function __construct(
        private readonly LoanLedgerService $loanLedger,
        private readonly MemberCashOutService $cashOuts,
    ) {}

    public function offersCashOut(Loan $loan): bool
    {
        if (! LoanSettings::allowExcessFundCashOut()) {
            return false;
        }

        if (LoanFundingStrategy::normalize($loan->funding_strategy) !== LoanFundingStrategy::SPLIT_PERCENTAGE) {
            return false;
        }

        if (! $loan->isFullyDisbursed()) {
            return false;
        }

        if (! in_array($loan->status, ['active', 'completed', 'early_settled', 'defaulted', 'transferred'], true)) {
            return false;
        }

        return $this->remainingEligibleAmount($loan) > 0.00001;
    }

    public function disbursementExcessAmount(Loan $loan): float
    {
        $fundBefore = $this->memberFundBalanceAtDisbursement($loan);

        if ($fundBefore === null) {
            return 0.0;
        }

        if (LoanFundingStrategy::normalize($loan->funding_strategy) !== LoanFundingStrategy::SPLIT_PERCENTAGE) {
            return 0.0;
        }

        $memberPortion = (float) ($loan->member_portion ?? 0);

        if ($memberPortion <= 0.00001) {
            $memberPortion = LoanSettings::resolveFundingPortions(
                (float) $loan->amount_approved,
                $fundBefore,
                $loan->funding_strategy,
            )['member_portion'];
        }

        return round(max(0.0, $fundBefore - $memberPortion), 2);
    }

    public function alreadyTransferredAmount(Loan $loan): float
    {
        $descriptionNeedle = '%'.__('Loan #:id — excess fund to cash', ['id' => $loan->id]).'%';

        return (float) Transaction::query()
            ->where('type', 'debit')
            ->where('reference_type', Loan::class)
            ->where('reference_id', $loan->getKey())
            ->where('description', 'like', $descriptionNeedle)
            ->whereHas('account', function ($query) use ($loan): void {
                $query->where('member_id', $loan->member_id)
                    ->where('type', 'fund')
                    ->where('is_master', false);
            })
            ->sum('amount');
    }

    public function remainingEligibleAmount(Loan $loan): float
    {
        return round(max(0.0, $this->disbursementExcessAmount($loan) - $this->alreadyTransferredAmount($loan)), 2);
    }

    public function maxTransferableAmount(Loan $loan): float
    {
        return $this->remainingEligibleAmount($loan);
    }

    public function currentMemberFundBalance(Loan $loan): float
    {
        $loan->loadMissing('member');
        $member = $loan->member;

        if ($member === null) {
            return 0.0;
        }

        $member->unsetRelation('fundAccount');

        return round($member->getFundBalance(), 2);
    }

    /**
     * @return array{
     *     fund_balance_at_disbursement: float|null,
     *     disbursement_excess: float,
     *     already_transferred: float,
     *     remaining_eligible: float,
     *     max_transferable: float,
     *     member_fund_balance: float,
     *     fund_shortfall: float,
     * }
     */
    public function summary(Loan $loan): array
    {
        $loan->loadMissing('member');
        $member = $loan->member;
        $member?->unsetRelation('fundAccount');

        $remainingEligible = $this->remainingEligibleAmount($loan);
        $memberFundBalance = $this->currentMemberFundBalance($loan);

        return [
            'fund_balance_at_disbursement' => $this->memberFundBalanceAtDisbursement($loan),
            'disbursement_excess' => $this->disbursementExcessAmount($loan),
            'already_transferred' => $this->alreadyTransferredAmount($loan),
            'remaining_eligible' => $remainingEligible,
            'max_transferable' => $remainingEligible,
            'member_fund_balance' => $memberFundBalance,
            'fund_shortfall' => round(max(0.0, $remainingEligible - max(0.0, $memberFundBalance)), 2),
        ];
    }

    public function cashOut(
        Loan $loan,
        float $amount,
        ?string $notes = null,
        ?int $reviewedBy = null,
        ?CarbonInterface $transactedAt = null,
    ): CashOutRequest {
        if (! $this->offersCashOut($loan)) {
            throw new InvalidArgumentException(__('This loan is not eligible for split excess fund cash-out.'));
        }

        if ($amount <= 0.00001) {
            throw new InvalidArgumentException(__('Enter an amount greater than zero.'));
        }

        $maxTransferable = $this->maxTransferableAmount($loan);

        if ($amount > $maxTransferable + 0.00001) {
            throw new InvalidArgumentException(__('Amount exceeds the transferable split excess fund balance (:max).', [
                'max' => number_format($maxTransferable, 2),
            ]));
        }

        $at = $transactedAt ?? BusinessDay::now();
        $this->assertTransactedAtValid($loan, $at);

        $loan->loadMissing('member');
        $member = $loan->member;

        if ($member === null) {
            throw new RuntimeException(__('Loan member is required.'));
        }

        $cashOutNotes = $notes !== null && trim($notes) !== ''
            ? trim($notes)
            : __('Auto cash-out of loan #:id split excess fund', ['id' => $loan->id]);

        return DB::transaction(function () use ($loan, $member, $amount, $cashOutNotes, $reviewedBy, $at): CashOutRequest {
            $this->loanLedger->transferMemberFundBalanceToCash(
                $loan,
                $amount,
                $at,
                allowNegativeMemberFundBalance: true,
            );

            return MemberCashOutService::withoutNotifications(function () use ($member, $amount, $cashOutNotes, $reviewedBy, $at, $loan): CashOutRequest {
                $request = $this->cashOuts->submit(
                    $member->fresh(),
                    $amount,
                    $cashOutNotes,
                    bypassAvailabilityGuard: true,
                );

                $this->cashOuts->accept(
                    $request->fresh(),
                    $reviewedBy,
                    __('Loan #:id split excess fund cash-out', ['id' => $loan->id]),
                    $at,
                    bypassAvailabilityGuard: true,
                );

                return $request->fresh();
            });
        });
    }

    private function memberFundBalanceAtDisbursement(Loan $loan): ?float
    {
        return $this->inferMemberFundBalanceAtDisbursement($loan);
    }

    private function inferMemberFundBalanceAtDisbursement(Loan $loan): ?float
    {
        if ($loan->member_fund_balance_at_disbursement !== null) {
            return (float) $loan->member_fund_balance_at_disbursement;
        }

        return $this->reconstructMemberFundBalanceBeforeLoanDisbursement($loan);
    }

    private function reconstructMemberFundBalanceBeforeLoanDisbursement(Loan $loan): ?float
    {
        $loan->loadMissing('member.fundAccount');
        $fundAccount = $loan->member?->fundAccount;

        if ($fundAccount === null) {
            return null;
        }

        $firstDisbursementDebit = Transaction::query()
            ->where('account_id', $fundAccount->id)
            ->where('type', 'debit')
            ->where('reference_type', Loan::class)
            ->where('reference_id', $loan->getKey())
            ->orderBy('transacted_at')
            ->orderBy('id')
            ->first();

        if ($firstDisbursementDebit === null) {
            return null;
        }

        $beforeAt = $firstDisbursementDebit->transacted_at;
        $beforeId = (int) $firstDisbursementDebit->id;

        $credits = (float) Transaction::query()
            ->where('account_id', $fundAccount->id)
            ->where('type', 'credit')
            ->where(function ($query) use ($beforeAt, $beforeId): void {
                $query->where('transacted_at', '<', $beforeAt)
                    ->orWhere(function ($query) use ($beforeAt, $beforeId): void {
                        $query->where('transacted_at', $beforeAt)
                            ->where('id', '<', $beforeId);
                    });
            })
            ->sum('amount');

        $debits = (float) Transaction::query()
            ->where('account_id', $fundAccount->id)
            ->where('type', 'debit')
            ->where(function ($query) use ($beforeAt, $beforeId): void {
                $query->where('transacted_at', '<', $beforeAt)
                    ->orWhere(function ($query) use ($beforeAt, $beforeId): void {
                        $query->where('transacted_at', $beforeAt)
                            ->where('id', '<', $beforeId);
                    });
            })
            ->sum('amount');

        return round($credits - $debits, 2);
    }

    public function disbursementAt(Loan $loan): ?CarbonInterface
    {
        if ($loan->disbursed_at !== null) {
            return Carbon::parse($loan->disbursed_at);
        }

        $firstDisbursement = $loan->disbursements()->oldest('disbursed_at')->value('disbursed_at');

        return $firstDisbursement !== null ? Carbon::parse($firstDisbursement) : null;
    }

    public function assertTransactedAtValid(Loan $loan, CarbonInterface $transactedAt): void
    {
        $disbursedAt = $this->disbursementAt($loan);

        if ($disbursedAt === null) {
            throw new InvalidArgumentException(__('Loan disbursement date is not recorded.'));
        }

        if (Carbon::parse($transactedAt)->toDateString() < $disbursedAt->toDateString()) {
            throw new InvalidArgumentException(__('Cash-out date must be on or after the loan disbursement date (:date).', [
                'date' => $disbursedAt->toDateString(),
            ]));
        }
    }
}
