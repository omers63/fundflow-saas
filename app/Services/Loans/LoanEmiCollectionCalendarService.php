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
     *     to_collect: int,
     *     to_collect_amount: float,
     *     paid_on: int,
     *     paid_on_amount: float,
     * }>
     */
    public function monthGrid(int $year, int $month): array
    {
        $start = Carbon::create($year, $month, 1)->startOfDay();
        $end = $start->copy()->endOfMonth();

        $dueGrouped = LoanInstallment::query()
            ->whereBetween('due_date', [$start->toDateString(), $end->toDateString()])
            ->whereHas('loan', fn (Builder $loan): Builder => $loan->whereIn('status', ['active', 'transferred']))
            ->get(['id', 'due_date', 'status', 'amount'])
            ->groupBy(fn (LoanInstallment $installment): string => Carbon::parse($installment->due_date)->toDateString());

        $paidGrouped = LoanInstallment::query()
            ->whereNotNull('paid_at')
            ->whereBetween('paid_at', [$start, $end->copy()->endOfDay()])
            ->whereHas('loan', fn (Builder $loan): Builder => $loan->whereIn('status', [
                'active',
                'transferred',
                'completed',
                'early_settled',
            ]))
            ->get(['id', 'paid_at', 'amount'])
            ->groupBy(fn (LoanInstallment $installment): string => Carbon::parse($installment->paid_at)->toDateString());

        $days = [];

        for ($day = 1; $day <= $end->day; $day++) {
            $date = $start->copy()->day($day)->toDateString();
            $dueItems = $dueGrouped->get($date, collect());
            $openDueItems = $dueItems->whereIn('status', ['pending', 'overdue']);
            $paidItems = $paidGrouped->get($date, collect());

            $days[$day] = [
                'date' => $date,
                'to_collect' => $openDueItems->count(),
                'to_collect_amount' => round((float) $openDueItems->sum('amount'), 2),
                'paid_on' => $paidItems->count(),
                'paid_on_amount' => round((float) $paidItems->sum('amount'), 2),
            ];
        }

        return $days;
    }

    /**
     * @return Collection<int, LoanInstallment>
     */
    public function installmentsForDate(Carbon $date): Collection
    {
        $dateString = $date->toDateString();

        return LoanInstallment::query()
            ->where(function (Builder $query) use ($dateString): void {
                $query->whereDate('due_date', $dateString)
                    ->orWhereDate('paid_at', $dateString);
            })
            ->whereHas('loan', fn (Builder $loan): Builder => $loan->whereIn('status', [
                'active',
                'transferred',
                'completed',
                'early_settled',
            ]))
            ->with(['loan.member'])
            ->orderBy('due_date')
            ->orderBy('installment_number')
            ->get();
    }
}
