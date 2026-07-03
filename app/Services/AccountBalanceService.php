<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\Transaction;
use App\Support\BusinessDay;
use Carbon\CarbonInterface;

final class AccountBalanceService
{
    public function balanceAtDate(Account $account, CarbonInterface $date): float
    {
        $asOf = $date->copy()->endOfDay();

        $credits = (float) Transaction::query()
            ->where('account_id', $account->id)
            ->where('type', 'credit')
            ->where('transacted_at', '<=', $asOf)
            ->sum('amount');

        $debits = (float) Transaction::query()
            ->where('account_id', $account->id)
            ->where('type', 'debit')
            ->where('transacted_at', '<=', $asOf)
            ->sum('amount');

        return round($credits - $debits, 2);
    }

    public function masterFundBalanceAtDate(CarbonInterface $date): float
    {
        $masterFund = Account::masterFund();

        if ($masterFund === null) {
            return 0.0;
        }

        if ($date->copy()->endOfDay()->isSameDay(BusinessDay::today())) {
            return round((float) $masterFund->balance, 2);
        }

        return $this->balanceAtDate($masterFund, $date);
    }
}
