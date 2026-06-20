<?php

declare(strict_types=1);

namespace App\Services\Members;

use App\Models\Tenant\Loan;
use App\Models\Tenant\Member;
use App\Models\Tenant\Setting;
use Illuminate\Database\Eloquent\Builder;

final class MemberGuarantorExposureService
{
    /**
     * @return array{
     *     loan_count: int,
     *     total_exposure: float,
     *     max_single_exposure: float,
     *     has_risk: bool,
     *     delinquent_count: int,
     * }
     */
    public function summaryForMember(Member $member): array
    {
        $loans = $this->guaranteedLoansQuery($member)->get();

        $totalExposure = 0.0;
        $maxSingle = 0.0;
        $delinquentCount = 0;

        foreach ($loans as $loan) {
            $outstanding = $loan->getOutstandingBalance();
            $totalExposure += $outstanding;
            $maxSingle = max($maxSingle, $outstanding);

            if ($this->loanHasExposureRisk($loan)) {
                $delinquentCount++;
            }
        }

        return [
            'loan_count' => $loans->count(),
            'total_exposure' => $totalExposure,
            'max_single_exposure' => $maxSingle,
            'has_risk' => $delinquentCount > 0,
            'delinquent_count' => $delinquentCount,
        ];
    }

    public function guaranteedLoansQuery(Member $member): Builder
    {
        return Loan::query()
            ->where('guarantor_member_id', $member->id)
            ->whereNotIn('status', ['cancelled', 'rejected'])
            ->with(['member'])
            ->withCount([
                'installments as overdue_installments_count' => fn (Builder $query): Builder => $query->where('status', 'overdue'),
            ])
            ->orderByDesc('applied_at');
    }

    public function memberHasGuaranteedLoans(Member $member): bool
    {
        return $this->guaranteedLoansQuery($member)->exists();
    }

    public function loanHasExposureRisk(Loan $loan): bool
    {
        if (in_array($loan->status, ['defaulted'], true)) {
            return true;
        }

        if ($loan->guarantor_liability_transferred_at !== null) {
            return true;
        }

        if ((int) ($loan->overdue_installments_count ?? 0) > 0) {
            return true;
        }

        $grace = Setting::loanDefaultGraceCycles();

        return $loan->late_repayment_count >= $grace;
    }

    public function exposureRiskLabel(Loan $loan): string
    {
        return $this->loanHasExposureRisk($loan)
            ? __('At risk')
            : __('Normal');
    }
}
