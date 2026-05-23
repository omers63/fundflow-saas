<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\MigrationCycleStub;
use App\Support\ContributionPolicySettings;
use InvalidArgumentException;

/**
 * Master account invariants per fund_management_system_requirements.md §1.3.
 */
class MasterAccountInvariantService
{
    /**
     * @return array{
     *     balanced: bool,
     *     master_fund: float,
     *     member_fund_sum: float,
     *     backdated_due_sum: float,
     *     expected_master_fund: float,
     *     master_cash: float,
     *     member_cash_sum: float,
     *     fund_delta: float,
     *     cash_delta: float
     * }
     */
    public function check(): array
    {
        $masterFund = (float) (Account::masterFund()?->balance ?? 0);
        $masterCash = (float) (Account::masterCash()?->balance ?? 0);

        $memberFundSum = (float) Account::query()
            ->where('is_master', false)
            ->where('type', 'fund')
            ->sum('balance');

        $memberCashSum = (float) Account::query()
            ->where('is_master', false)
            ->where('type', 'cash')
            ->sum('balance');

        $backdatedDueSum = (float) MigrationCycleStub::query()
            ->where('classification', MigrationCycleStub::CLASS_BACKDATED_DUE)
            ->where('status', '!=', 'closed')
            ->sum('amount_due');

        $expectedMasterFund = $memberFundSum + $backdatedDueSum;

        $tolerance = ContributionPolicySettings::reconTolerance();
        $fundDelta = abs($masterFund - $expectedMasterFund);
        $cashDelta = abs($masterCash - $memberCashSum);

        return [
            'balanced' => $fundDelta <= $tolerance && $cashDelta <= $tolerance,
            'master_fund' => $masterFund,
            'member_fund_sum' => $memberFundSum,
            'backdated_due_sum' => $backdatedDueSum,
            'expected_master_fund' => $expectedMasterFund,
            'master_cash' => $masterCash,
            'member_cash_sum' => $memberCashSum,
            'fund_delta' => $fundDelta,
            'cash_delta' => $cashDelta,
        ];
    }

    public function assert(): void
    {
        $result = $this->check();

        if ($result['balanced']) {
            return;
        }

        throw new InvalidArgumentException(__(
            'Master account invariant failed (MASTER_IMBALANCE). Fund delta: :fund_delta (includes backdated due :backdated), Cash delta: :cash_delta',
            [
                'fund_delta' => number_format($result['fund_delta'], 2),
                'backdated' => number_format($result['backdated_due_sum'], 2),
                'cash_delta' => number_format($result['cash_delta'], 2),
            ],
        ));
    }
}
