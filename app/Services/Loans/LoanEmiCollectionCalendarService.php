<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\LoanInstallment;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

final class LoanEmiCollectionCalendarService
{
    /**
     * @return array<int, array{
     *     date: string,
     *     total: int,
     *     collected: int,
     *     pending: int,
     *     overdue: int,
     *     tone: 'empty'|'collected'|'pending'|'overdue'|'mixed',
     * }>
     */
    public function monthGrid(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        $grouped = LoanInstallment::query()
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('loan', fn (Builder $loan): Builder => $loan->whereIn('status', ['active', 'transferred']))
            ->get(['id', 'due_date', 'status'])
            ->groupBy(fn (LoanInstallment $installment): string => Carbon::parse($installment->due_date)->toDateString());

        $days = [];

        for ($day = 1; $day <= $end->day; $day++) {
            $date = $start->copy()->day($day)->toDateString();
            $items = $grouped->get($date, collect());
            $collected = $items->where('status', 'paid')->count();
            $pending = $items->where('status', 'pending')->count();
            $overdue = $items->where('status', 'overdue')->count();
            $total = $items->count();

            $days[$day] = [
                'date' => $date,
                'total' => $total,
                'collected' => $collected,
                'pending' => $pending,
                'overdue' => $overdue,
                'tone' => $this->dayTone($total, $collected, $pending, $overdue),
            ];
        }

        return $days;
    }

    /**
     * @return Collection<int, LoanInstallment>
     */
    public function installmentsForDate(Carbon $date): Collection
    {
        return LoanInstallment::query()
            ->whereDate('due_date', $date->toDateString())
            ->whereHas('loan', fn (Builder $loan): Builder => $loan->whereIn('status', ['active', 'transferred']))
            ->with(['loan.member'])
            ->orderBy('installment_number')
            ->get();
    }

    /**
     * @return 'empty'|'collected'|'pending'|'overdue'|'mixed'
     */
    private function dayTone(int $total, int $collected, int $pending, int $overdue): string
    {
        if ($total === 0) {
            return 'empty';
        }

        if ($overdue > 0) {
            return $overdue === $total ? 'overdue' : 'mixed';
        }

        if ($pending > 0) {
            return $pending === $total ? 'pending' : 'mixed';
        }

        if ($collected === $total) {
            return 'collected';
        }

        return 'mixed';
    }
}
