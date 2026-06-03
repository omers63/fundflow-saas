<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use Illuminate\Support\Collection;

final class MemberFeeArrearsService
{
    public function __construct(
        private AccountingService $accounting,
    ) {}

    /**
     * @return Collection<int, array{application: MembershipApplication, arrears: float}>
     */
    public function subscriptionArrears(Member $member): Collection
    {
        return MembershipApplication::query()
            ->where('member_id', $member->id)
            ->where('status', 'approved')
            ->orderBy('id')
            ->get()
            ->map(function (MembershipApplication $application): ?array {
                $arrears = $this->subscriptionArrearsForApplication($application);

                if ($arrears <= 0.00001) {
                    return null;
                }

                return [
                    'application' => $application,
                    'arrears' => $arrears,
                ];
            })
            ->filter()
            ->values();
    }

    public function subscriptionArrearsForApplication(MembershipApplication $application): float
    {
        $required = (float) ($application->membership_fee_required_amount ?? 0);
        $transferred = (float) ($application->membership_fee_amount ?? 0);
        $arrears = max(0.0, round($required - $transferred, 2));

        if ($arrears <= 0.00001 && is_string($application->rejection_reason)) {
            if (preg_match('/Subscription fee arrears:\s*([0-9]+(?:\.[0-9]+)?)/', $application->rejection_reason, $matches) === 1) {
                $arrears = (float) $matches[1];
            }
        }

        return max(0.0, round($arrears, 2));
    }

    /**
     * @return Collection<int, array{contribution: Contribution, outstanding: float}>
     */
    public function contributionLateFeeArrears(Member $member): Collection
    {
        return Contribution::query()
            ->where('member_id', $member->id)
            ->whereNotNull('late_fee_amount')
            ->where('late_fee_amount', '>', 0)
            ->orderBy('period')
            ->get()
            ->map(function (Contribution $contribution): ?array {
                $outstanding = max(
                    0.0,
                    round((float) $contribution->late_fee_amount - $this->accounting->contributionLateFeeCollectedAmount($contribution), 2),
                );

                if ($outstanding <= 0.00001) {
                    return null;
                }

                return [
                    'contribution' => $contribution,
                    'outstanding' => $outstanding,
                ];
            })
            ->filter()
            ->values();
    }

    /**
     * @return Collection<int, array{installment: LoanInstallment, outstanding: float}>
     */
    public function installmentLateFeeArrears(Member $member): Collection
    {
        return LoanInstallment::query()
            ->whereNotNull('late_fee_amount')
            ->where('late_fee_amount', '>', 0)
            ->whereHas('loan', fn ($query) => $query->where('member_id', $member->id))
            ->with('loan')
            ->orderBy('due_date')
            ->get()
            ->map(function (LoanInstallment $installment): ?array {
                $outstanding = max(
                    0.0,
                    round((float) $installment->late_fee_amount - $this->accounting->installmentLateFeeCollectedAmount($installment), 2),
                );

                if ($outstanding <= 0.00001) {
                    return null;
                }

                return [
                    'installment' => $installment,
                    'outstanding' => $outstanding,
                ];
            })
            ->filter()
            ->values();
    }

    public function totalFeeArrears(Member $member): float
    {
        $subscription = $this->subscriptionArrears($member)->sum('arrears');
        $contributions = $this->contributionLateFeeArrears($member)->sum('outstanding');
        $installments = $this->installmentLateFeeArrears($member)->sum('outstanding');

        return round($subscription + $contributions + $installments, 2);
    }
}
