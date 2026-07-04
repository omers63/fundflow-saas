<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Account;
use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\FundPosting;
use App\Support\Insights\InsightFormatter;

final class TreasuryForecastService
{
    /**
     * @return array<string, float|int|string|null>
     */
    public function snapshot(): array
    {
        $masterCash = (float) (Account::masterCash()?->balance ?? 0);

        $pendingDepositCount = FundPosting::query()
            ->where('status', 'pending')
            ->count();
        $pendingDepositAmount = (float) FundPosting::query()
            ->where('status', 'pending')
            ->sum('amount');

        $pendingCashOutCount = CashOutRequest::query()
            ->where('status', 'pending')
            ->count();
        $pendingCashOutAmount = (float) CashOutRequest::query()
            ->where('status', 'pending')
            ->sum('amount');

        $unclearedDepositCount = FundPosting::query()
            ->where('status', 'accepted')
            ->whereHas('bankTransaction', fn ($query) => $query->where('is_cleared', false))
            ->count();
        $unclearedDepositAmount = (float) FundPosting::query()
            ->where('status', 'accepted')
            ->whereHas('bankTransaction', fn ($query) => $query->where('is_cleared', false))
            ->sum('amount');

        $unclearedCashOutCount = CashOutRequest::query()
            ->where('status', 'accepted')
            ->whereHas('bankTransaction', fn ($query) => $query->where('is_cleared', false))
            ->count();
        $unclearedCashOutAmount = (float) CashOutRequest::query()
            ->where('status', 'accepted')
            ->whereHas('bankTransaction', fn ($query) => $query->where('is_cleared', false))
            ->sum('amount');

        $pendingNetAmount = round($pendingDepositAmount - $pendingCashOutAmount, 2);
        $projectedAvailableCash = round($masterCash + $pendingNetAmount, 2);
        $clearingBacklogAmount = round($unclearedDepositAmount + $unclearedCashOutAmount, 2);
        $coveragePercent = $pendingCashOutAmount > 0
            ? (int) round((($masterCash + $pendingDepositAmount) / $pendingCashOutAmount) * 100)
            : null;

        return [
            'currency' => InsightFormatter::currency(),
            'master_cash' => $masterCash,
            'pending_deposit_count' => $pendingDepositCount,
            'pending_deposit_amount' => round($pendingDepositAmount, 2),
            'pending_cash_out_count' => $pendingCashOutCount,
            'pending_cash_out_amount' => round($pendingCashOutAmount, 2),
            'uncleared_deposit_count' => $unclearedDepositCount,
            'uncleared_deposit_amount' => round($unclearedDepositAmount, 2),
            'uncleared_cash_out_count' => $unclearedCashOutCount,
            'uncleared_cash_out_amount' => round($unclearedCashOutAmount, 2),
            'pending_net_amount' => $pendingNetAmount,
            'projected_available_cash' => $projectedAvailableCash,
            'clearing_backlog_count' => $unclearedDepositCount + $unclearedCashOutCount,
            'clearing_backlog_amount' => $clearingBacklogAmount,
            'coverage_percent' => $coveragePercent,
            'tone' => $this->tone($projectedAvailableCash, $pendingCashOutAmount, $clearingBacklogAmount),
        ];
    }

    private function tone(float $projectedAvailableCash, float $pendingCashOutAmount, float $clearingBacklogAmount): string
    {
        if ($projectedAvailableCash < 0) {
            return 'danger';
        }

        if ($pendingCashOutAmount > 0 || $clearingBacklogAmount > 0) {
            return 'warning';
        }

        return 'success';
    }
}
