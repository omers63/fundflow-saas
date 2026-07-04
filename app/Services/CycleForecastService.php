<?php

declare(strict_types=1);

namespace App\Services;

use App\Support\BusinessDay;
use Carbon\Carbon;

final class CycleForecastService
{
    public function __construct(
        private ContributionCycleService $cycles,
    ) {}

    /**
     * @return array<string, float|int|string>
     */
    public function project(
        int $month,
        int $year,
        int $completedCount,
        int $expectedCount,
        float $completedAmount,
        float $expectedAmount,
    ): array {
        $now = BusinessDay::now();
        $start = $this->cycles->cycleStartAt($month, $year);
        $deadline = $this->cycles->cycleDueEndAt($month, $year);

        $daysTotal = max(1, (int) ($start->diffInDays($deadline) + 1));
        $daysElapsed = $this->daysElapsed($now, $start, $deadline, $daysTotal);
        $daysRemaining = $this->daysRemaining($now, $deadline);

        $remainingCount = max(0, $expectedCount - $completedCount);
        $remainingAmount = max(0.0, round($expectedAmount - $completedAmount, 2));

        $currentCountPerDay = $daysElapsed > 0
            ? round($completedCount / $daysElapsed, 2)
            : 0.0;
        $currentAmountPerDay = $daysElapsed > 0
            ? round($completedAmount / $daysElapsed, 2)
            : 0.0;

        $projectedCount = $daysElapsed > 0
            ? min($expectedCount, (int) round($currentCountPerDay * $daysTotal))
            : 0;
        $projectedAmount = $daysElapsed > 0
            ? min($expectedAmount, round($currentAmountPerDay * $daysTotal, 2))
            : 0.0;

        $projectedClosePercent = $expectedCount > 0
            ? (int) round(($projectedCount / $expectedCount) * 100)
            : 0;
        $projectedAmountPercent = $expectedAmount > 0
            ? (int) round(($projectedAmount / $expectedAmount) * 100)
            : 0;

        $requiredCountPerDay = $daysRemaining > 0
            ? round($remainingCount / $daysRemaining, 2)
            : (float) $remainingCount;
        $requiredAmountPerDay = $daysRemaining > 0
            ? round($remainingAmount / $daysRemaining, 2)
            : $remainingAmount;

        return [
            'period_label' => $this->cycles->periodLabel($month, $year),
            'window_label' => $this->cycles->cycleWindowDescription($month, $year),
            'deadline_label' => $deadline->locale(app()->getLocale())->translatedFormat('j M Y'),
            'days_total' => $daysTotal,
            'days_elapsed' => $daysElapsed,
            'days_remaining' => $daysRemaining,
            'completed_count' => $completedCount,
            'expected_count' => $expectedCount,
            'remaining_count' => $remainingCount,
            'completed_amount' => round($completedAmount, 2),
            'expected_amount' => round($expectedAmount, 2),
            'remaining_amount' => $remainingAmount,
            'current_count_per_day' => $currentCountPerDay,
            'current_amount_per_day' => $currentAmountPerDay,
            'required_count_per_day' => $requiredCountPerDay,
            'required_amount_per_day' => $requiredAmountPerDay,
            'projected_count' => $projectedCount,
            'projected_amount' => $projectedAmount,
            'projected_close_percent' => $projectedClosePercent,
            'projected_amount_percent' => $projectedAmountPercent,
            'tone' => $this->tone($projectedClosePercent, $daysRemaining, $remainingCount),
        ];
    }

    private function daysElapsed(Carbon $now, Carbon $start, Carbon $deadline, int $daysTotal): int
    {
        if ($now->lt($start)) {
            return 0;
        }

        $effectiveNow = $now->copy()->startOfDay();
        $effectiveDeadline = $deadline->copy()->startOfDay();

        if ($effectiveNow->gt($effectiveDeadline)) {
            return $daysTotal;
        }

        return min($daysTotal, (int) ($start->copy()->startOfDay()->diffInDays($effectiveNow) + 1));
    }

    private function daysRemaining(Carbon $now, Carbon $deadline): int
    {
        if ($now->gt($deadline)) {
            return 0;
        }

        return (int) $now->copy()->startOfDay()->diffInDays($deadline->copy()->startOfDay());
    }

    private function tone(int $projectedClosePercent, int $daysRemaining, int $remainingCount): string
    {
        if ($remainingCount === 0 || $projectedClosePercent >= 100) {
            return 'success';
        }

        if ($daysRemaining === 0 || $projectedClosePercent < 85) {
            return 'danger';
        }

        return 'warning';
    }
}
