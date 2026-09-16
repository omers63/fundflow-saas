<?php

declare(strict_types=1);

namespace App\Services\Gateway;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\FundPosting;
use App\Models\Tenant\GatewayPayment;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Services\MemberFeeArrearsService;
use Illuminate\Database\Eloquent\Model;

final class GatewayDueResolver
{
    public function __construct(
        private readonly MemberFeeArrearsService $feeArrears,
    ) {}

    /**
     * @return array{
     *     purpose: string,
     *     amount: float,
     *     label: string,
     *     payable: ?Model
     * }|null
     */
    public function nextContributionDue(Member $member): ?array
    {
        $contribution = Contribution::query()
            ->where('member_id', $member->id)
            ->whereIn('status', ['pending', 'failed'])
            ->orderBy('period')
            ->get()
            ->first(function (Contribution $contribution): bool {
                return $this->contributionOutstanding($contribution) > 0.001;
            });

        if ($contribution === null) {
            return null;
        }

        $amount = $this->contributionOutstanding($contribution);

        return [
            'purpose' => GatewayPayment::PURPOSE_CONTRIBUTION,
            'amount' => $amount,
            'label' => __('Contribution due (:period)', ['period' => $contribution->period]),
            'payable' => $contribution,
        ];
    }

    /**
     * @return array{
     *     purpose: string,
     *     amount: float,
     *     label: string,
     *     payable: ?Model
     * }|null
     */
    public function nextEmiDue(?Loan $loan = null, ?Member $member = null): ?array
    {
        $query = LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->orderBy('due_date');

        if ($loan !== null) {
            $query->where('loan_id', $loan->id);
        } elseif ($member !== null) {
            $query->whereHas('loan', fn ($q) => $q->where('member_id', $member->id)->where('status', 'active'));
        } else {
            return null;
        }

        $installment = $query->get()->first(function (LoanInstallment $installment): bool {
            return $this->installmentOutstanding($installment) > 0.001;
        });

        if ($installment === null) {
            return null;
        }

        $amount = $this->installmentOutstanding($installment);

        return [
            'purpose' => GatewayPayment::PURPOSE_EMI,
            'amount' => $amount,
            'label' => __('Loan installment #:number due', ['number' => $installment->installment_number]),
            'payable' => $installment,
        ];
    }

    /**
     * @return array{
     *     purpose: string,
     *     amount: float,
     *     label: string,
     *     payable: ?Model
     * }|null
     */
    public function feeArrearsDue(Member $member): ?array
    {
        $total = $this->feeArrears->totalFeeArrears($member);

        if ($total <= 0.001) {
            return null;
        }

        return [
            'purpose' => GatewayPayment::PURPOSE_FEE,
            'amount' => $total,
            'label' => __('Fee arrears'),
            'payable' => null,
        ];
    }

    /**
     * @return array{
     *     purpose: string,
     *     amount: float,
     *     label: string,
     *     payable: ?Model
     * }|null
     */
    public function openDepositDue(Member $member, ?FundPosting $posting = null): ?array
    {
        if ($posting === null) {
            $posting = FundPosting::query()
                ->where('member_id', $member->id)
                ->where('status', 'pending')
                ->orderByDesc('id')
                ->first();
        }

        if ($posting === null || $posting->status !== 'pending') {
            return null;
        }

        $amount = round((float) $posting->amount, 2);

        if ($amount <= 0.001) {
            return null;
        }

        return [
            'purpose' => GatewayPayment::PURPOSE_DEPOSIT,
            'amount' => $amount,
            'label' => __('Pending deposit #:id', ['id' => $posting->id]),
            'payable' => $posting,
        ];
    }

    public function contributionOutstanding(Contribution $contribution): float
    {
        $due = (float) ($contribution->amount_due ?? $contribution->amount ?? 0);
        $collected = (float) ($contribution->amount_collected ?? 0);

        return max(0.0, round($due - $collected, 2));
    }

    public function installmentOutstanding(LoanInstallment $installment): float
    {
        $due = (float) $installment->amount + (float) ($installment->late_fee_amount ?? 0);
        $collected = (float) ($installment->amount_collected ?? 0);

        return max(0.0, round($due - $collected, 2));
    }
}
