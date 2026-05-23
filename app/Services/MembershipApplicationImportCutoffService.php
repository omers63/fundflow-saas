<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Models\Tenant\MembershipApplication;
use Carbon\Carbon;
use InvalidArgumentException;

class MembershipApplicationImportCutoffService
{
    public function __construct(
        private readonly MigrationOpeningBalanceService $openingBalances,
    ) {}

    public function applyOnApproval(MembershipApplication $application, Member $member): void
    {
        if ($application->import_arrears_cutoff_date === null) {
            return;
        }

        $cutoff = Carbon::parse($application->import_arrears_cutoff_date);
        $cash = (float) ($application->import_cutoff_cash_balance ?? 0);
        $fund = (float) ($application->import_cutoff_fund_balance ?? 0);

        $member->update([
            'contribution_arrears_cutoff_date' => $cutoff->toDateString(),
        ]);

        if ($cash <= 0.00001 && $fund <= 0.00001) {
            return;
        }

        if ($member->fresh()->opening_balances_posted_at !== null) {
            throw new InvalidArgumentException(__('Opening balances were already posted for this member.'));
        }

        $this->openingBalances->postOpeningBalances(
            $member->fresh(),
            $cash,
            $fund,
            $cutoff,
            'IMPORT_CUTOFF',
        );
    }
}
