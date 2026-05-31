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
        private readonly ContributionCollectionCycleService $contributions,
    ) {}

    /**
     * Set import arrears cut-off on the member and clear pre-cut-off cycles before any cash is credited.
     */
    public function prepareCutoffOnApproval(MembershipApplication $application, Member $member): void
    {
        if ($application->import_arrears_cutoff_date === null) {
            return;
        }

        $cutoff = Carbon::parse($application->import_arrears_cutoff_date);

        $member->update([
            'contribution_arrears_cutoff_date' => $cutoff->toDateString(),
        ]);

        $this->contributions->dismissPreCutoffPendingContributions($member->fresh() ?? $member);
    }

    /**
     * Post import cut-off cash and fund balances (triggers contribution collection once).
     */
    public function postOpeningBalancesOnApproval(MembershipApplication $application, Member $member): void
    {
        if ($application->import_arrears_cutoff_date === null) {
            return;
        }

        $cash = (float) ($application->import_cutoff_cash_balance ?? 0);
        $fund = (float) ($application->import_cutoff_fund_balance ?? 0);

        if ($cash <= 0.00001 && $fund <= 0.00001) {
            return;
        }

        if ($member->fresh()->opening_balances_posted_at !== null) {
            throw new InvalidArgumentException(__('Opening balances were already posted for this member.'));
        }

        $cutoff = Carbon::parse($application->import_arrears_cutoff_date);

        $this->openingBalances->postOpeningBalances(
            $member->fresh(),
            $cash,
            $fund,
            $cutoff,
            'IMPORT_CUTOFF',
        );
    }

    public function applyOnApproval(MembershipApplication $application, Member $member): void
    {
        $this->prepareCutoffOnApproval($application, $member);
        $this->postOpeningBalancesOnApproval($application, $member);
    }
}
