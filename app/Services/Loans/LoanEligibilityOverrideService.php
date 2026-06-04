<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\LoanEligibilityOverride;
use App\Models\Tenant\Member;
use App\Services\FundAuditLogService;
use Illuminate\Support\Facades\Auth;

class LoanEligibilityOverrideService
{
    public function __construct(
        protected FundAuditLogService $audit,
    ) {}

    /**
     * Standing overrides (no linked loan) that bypass eligibility gates for a member.
     *
     * @return list<string>
     */
    public function overriddenGatesFor(Member|int $member): array
    {
        $memberId = $member instanceof Member ? (int) $member->id : $member;

        return LoanEligibilityOverride::query()
            ->where('member_id', $memberId)
            ->whereNull('loan_id')
            ->distinct()
            ->pluck('gate')
            ->values()
            ->all();
    }

    public function hasOverride(Member|int $member, string $gate): bool
    {
        return in_array($gate, $this->overriddenGatesFor($member), true);
    }

    public function record(
        int $memberId,
        string $gate,
        string $reason,
        ?int $loanId = null,
    ): LoanEligibilityOverride {
        $override = LoanEligibilityOverride::create([
            'loan_id' => $loanId,
            'member_id' => $memberId,
            'gate' => $gate,
            'reason' => $reason,
            'approved_by' => Auth::guard('tenant')->id(),
        ]);

        $this->audit->log('LOAN_ELIGIBILITY_OVERRIDE', 'loan', $override, null, [
            'gate' => $gate,
            'member_id' => $memberId,
            'loan_id' => $loanId,
        ]);

        return $override;
    }

    /**
     * @param  list<string>  $gates
     */
    public function recordMany(
        int $memberId,
        array $gates,
        string $reason,
        ?int $loanId = null,
    ): void {
        foreach (array_values(array_unique($gates)) as $gate) {
            $this->record($memberId, $gate, $reason, $loanId);
        }
    }
}
