<?php

declare(strict_types=1);

namespace App\Services\Savings;

use App\Models\Tenant\Member;
use App\Services\LoanService;

final class MemberLoanReadinessService
{
    public function __construct(
        private readonly LoanService $loans,
    ) {}

    /**
     * @return array{
     *     eligible: bool,
     *     reasons: list<string>,
     *     fund_balance: float,
     *     monthly_contribution: float,
     *     summary: string
     * }
     */
    public function forMember(Member $member): array
    {
        $eligibility = $this->loans->checkEligibility($member);
        $eligible = (bool) ($eligibility['eligible'] ?? false);
        /** @var list<string> $reasons */
        $reasons = array_values(array_map('strval', $eligibility['reasons'] ?? []));

        $fund = round($member->getFundBalance(), 2);
        $monthly = round((float) ($member->monthly_contribution_amount ?? 0), 2);

        $summary = $eligible
            ? __('You appear eligible to apply for a loan based on current rules.')
            : ($reasons[0] ?? __('Not currently eligible for a new loan.'));

        return [
            'eligible' => $eligible,
            'reasons' => $reasons,
            'fund_balance' => $fund,
            'monthly_contribution' => $monthly,
            'summary' => $summary,
        ];
    }
}
