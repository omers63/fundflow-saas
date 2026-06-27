<?php

declare(strict_types=1);

namespace App\Support\Insights;

use App\Models\Tenant\Account;
use App\Models\Tenant\Transaction;

final class MasterInvestNetReturn
{
    /**
     * @return array{returns_in: float, invested_out: float, net_return: float, is_negative: bool}
     */
    public static function summarize(Account $account): array
    {
        $investedOut = (float) Transaction::query()
            ->where('account_id', $account->id)
            ->where('type', 'debit')
            ->where('description', 'like', '%(invest out)%')
            ->sum('amount');

        $returnsIn = (float) Transaction::query()
            ->where('account_id', $account->id)
            ->where('type', 'credit')
            ->where('description', 'like', '%(investment return)%')
            ->sum('amount');

        $netReturn = $returnsIn - $investedOut;

        return [
            'returns_in' => $returnsIn,
            'invested_out' => $investedOut,
            'net_return' => $netReturn,
            'is_negative' => $investedOut > $returnsIn,
        ];
    }

    public static function netReturn(Account $account): float
    {
        return self::summarize($account)['net_return'];
    }
}
