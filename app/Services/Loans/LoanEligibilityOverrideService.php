<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\LoanEligibilityOverride;
use App\Services\FundAuditLogService;
use Illuminate\Support\Facades\Auth;

class LoanEligibilityOverrideService
{
    public function __construct(
        protected FundAuditLogService $audit,
    ) {}

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
}
