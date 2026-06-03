<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\Transaction;
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
     *     master_invest_from_fund_credits: float,
     *     master_expense_from_fund_credits: float,
     *     master_fund_pool: float,
     *     member_fund_sum: float,
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
        $masterInvest = Account::masterInvest();
        $masterInvestFromFundCredits = $masterInvest === null
            ? 0.0
            : (float) Transaction::query()
                ->where('account_id', $masterInvest->id)
                ->where('type', 'credit')
                ->where('description', 'like', '%(reserve funding)%')
                ->sum('amount');
        $masterExpense = Account::masterExpense();
        $masterExpenseFromFundCredits = $masterExpense === null
            ? 0.0
            : (float) Transaction::query()
                ->where('account_id', $masterExpense->id)
                ->where('type', 'credit')
                ->where('description', 'like', '%(reserve funding)%')
                ->sum('amount');
        $masterCash = (float) (Account::masterCash()?->balance ?? 0);

        $memberFundSum = (float) Account::query()
            ->where('is_master', false)
            ->where('type', 'fund')
            ->sum('balance');

        $memberCashSum = (float) Account::query()
            ->where('is_master', false)
            ->where('type', 'cash')
            ->sum('balance');

        $masterFundPool = $masterFund + $masterInvestFromFundCredits + $masterExpenseFromFundCredits;
        $expectedMasterFund = $memberFundSum;

        $tolerance = ContributionPolicySettings::reconTolerance();
        $fundDelta = round(abs($masterFundPool - $expectedMasterFund), 2);
        $cashDelta = round(abs($masterCash - $memberCashSum), 2);

        return [
            'balanced' => $fundDelta <= $tolerance && $cashDelta <= $tolerance,
            'master_fund' => $masterFund,
            'master_invest_from_fund_credits' => $masterInvestFromFundCredits,
            'master_expense_from_fund_credits' => $masterExpenseFromFundCredits,
            'master_fund_pool' => $masterFundPool,
            'member_fund_sum' => $memberFundSum,
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
            'Master account invariant failed (MASTER_IMBALANCE). Fund delta: :fund_delta, Cash delta: :cash_delta',
            [
                'fund_delta' => number_format($result['fund_delta'], 2),
                'cash_delta' => number_format($result['cash_delta'], 2),
            ],
        ));
    }
}
