<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\Member;
use App\Models\Tenant\Transaction;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;

final class MemberAccountBalanceService
{
    public function balanceAtDate(Member $member, string $accountType, CarbonInterface $date): float
    {
        $accountId = Account::query()
            ->where('member_id', $member->id)
            ->where('type', $accountType)
            ->value('id');

        if ($accountId === null) {
            return 0.0;
        }

        $asOf = $date->copy()->endOfDay();

        $credits = (float) Transaction::query()
            ->where('account_id', $accountId)
            ->where('type', 'credit')
            ->where('transacted_at', '<=', $asOf)
            ->sum('amount');

        $debits = (float) Transaction::query()
            ->where('account_id', $accountId)
            ->where('type', 'debit')
            ->where('transacted_at', '<=', $asOf)
            ->sum('amount');

        return round($credits - $debits, 2);
    }

    /**
     * @return array{fund: float, cash: float, total: float}
     */
    public function positiveFreezeCashOutBalances(Member $member, CarbonInterface $asOfDate): array
    {
        $asOfEnd = $asOfDate->copy()->endOfDay();

        if ($asOfEnd->isSameDay(BusinessDay::today())) {
            $member->loadMissing(['cashAccount', 'fundAccount']);
            $fund = round(max(0.0, $member->getFundBalance()), 2);
            $cash = round(max(0.0, $member->getCashBalance()), 2);
        } else {
            $fund = round(max(0.0, $this->balanceAtDate($member, 'fund', $asOfDate)), 2);
            $cash = round(max(0.0, $this->balanceAtDate($member, 'cash', $asOfDate)), 2);
        }

        return [
            'fund' => $fund,
            'cash' => $cash,
            'total' => round($fund + $cash, 2),
        ];
    }
}
