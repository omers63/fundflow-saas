<?php

declare(strict_types=1);

namespace App\Support\Insights;

use App\Models\Tenant\Contribution;
use App\Models\Tenant\Member;
use App\Services\ContributionCycleService;
use App\Support\BusinessDay;

final class DualProgressTrendBuilder
{
    public static function rateTone(int $rate, int $denominator): string
    {
        if ($denominator === 0) {
            return 'neutral';
        }

        if ($rate >= 90) {
            return 'success';
        }

        if ($rate >= 50) {
            return 'warning';
        }

        return 'danger';
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function sixMonthFundCollectionTrend(ContributionCycleService $cycles): array
    {
        $now = BusinessDay::now();
        $oldestMonth = $now->copy()->subMonths(5)->startOfMonth();
        $oldestPeriod = Contribution::periodDate((int) $oldestMonth->month, (int) $oldestMonth->year);
        $periodTotals = [];

        Contribution::query()
            ->where('period', '>=', $oldestPeriod)
            ->posted()
            ->get(['period', 'amount'])
            ->each(function (Contribution $contribution) use (&$periodTotals): void {
                $periodKey = Contribution::normalizePeriodKey($contribution->period);

                if ($periodKey === null) {
                    return;
                }

                $periodTotals[$periodKey] ??= [
                    'posted' => 0,
                    'posted_amount' => 0.0,
                ];
                $periodTotals[$periodKey]['posted']++;
                $periodTotals[$periodKey]['posted_amount'] += (float) $contribution->amount;
            });

        $trend = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i)->startOfMonth();
            $m = (int) $month->month;
            $y = (int) $month->year;
            $period = Contribution::periodDate($m, $y);
            $posted = (int) ($periodTotals[$period]['posted'] ?? 0);
            $postedAmount = (float) ($periodTotals[$period]['posted_amount'] ?? 0.0);
            $expected = $cycles->expectedCollectionTargetsForPeriod($m, $y);

            $trend[] = self::buildCollectionMonthRow(
                $month->locale(app()->getLocale())->translatedFormat('M'),
                $posted,
                $postedAmount,
                (int) $expected['expected_count'],
                (float) $expected['expected_amount'],
            );
        }

        return $trend;
    }

    /**
     * @return list<array<string, mixed>>
     */
    public static function sixMonthMemberCollectionTrend(Member $member, ContributionCycleService $cycles): array
    {
        $now = BusinessDay::now();
        $oldestMonth = $now->copy()->subMonths(5)->startOfMonth();
        $oldestPeriod = Contribution::periodDate((int) $oldestMonth->month, (int) $oldestMonth->year);
        $periodTotals = [];

        Contribution::query()
            ->where('member_id', $member->id)
            ->where('period', '>=', $oldestPeriod)
            ->get(['period', 'status', 'amount'])
            ->each(function (Contribution $contribution) use (&$periodTotals): void {
                $periodKey = Contribution::normalizePeriodKey($contribution->period);

                if ($periodKey === null || $contribution->status !== 'posted') {
                    return;
                }

                $periodTotals[$periodKey] ??= [
                    'posted' => 0,
                    'posted_amount' => 0.0,
                ];
                $periodTotals[$periodKey]['posted']++;
                $periodTotals[$periodKey]['posted_amount'] += (float) $contribution->amount;
            });

        $trend = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = $now->copy()->subMonths($i)->startOfMonth();
            $m = (int) $month->month;
            $y = (int) $month->year;
            $period = Contribution::periodDate($m, $y);
            $posted = (int) ($periodTotals[$period]['posted'] ?? 0);
            $postedAmount = (float) ($periodTotals[$period]['posted_amount'] ?? 0.0);
            $expected = self::memberExpectedTargets($member, $cycles, $m, $y);

            $trend[] = self::buildCollectionMonthRow(
                $month->locale(app()->getLocale())->translatedFormat('M'),
                $posted,
                $postedAmount,
                (int) $expected['expected_count'],
                (float) $expected['expected_amount'],
            );
        }

        return $trend;
    }

    /**
     * @return array{expected_count: int, expected_amount: float}
     */
    public static function memberExpectedTargets(
        Member $member,
        ContributionCycleService $cycles,
        int $month,
        int $year,
    ): array {
        if (! $cycles->memberCanApplyContributionForPeriod($member, $month, $year)) {
            return [
                'expected_count' => 0,
                'expected_amount' => 0.0,
            ];
        }

        return [
            'expected_count' => 1,
            'expected_amount' => (float) $member->monthly_contribution_amount,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildCollectionMonthRow(
        string $label,
        int $posted,
        float $postedAmount,
        int $expectedCount,
        float $expectedAmount,
    ): array {
        $collectionRate = $expectedCount > 0
            ? (int) round(($posted / $expectedCount) * 100)
            : ($posted > 0 ? 100 : 0);

        $amountCollectionRate = $expectedAmount > 0
            ? (int) round(($postedAmount / $expectedAmount) * 100)
            : ($postedAmount > 0 ? 100 : 0);

        return [
            'label' => $label,
            'posted' => $posted,
            'posted_amount' => $postedAmount,
            'expected_count' => $expectedCount,
            'expected_amount' => $expectedAmount,
            'collection_rate' => $collectionRate,
            'collection_rate_bar' => min(100, $collectionRate),
            'amount_collection_rate' => $amountCollectionRate,
            'amount_collection_rate_bar' => min(100, $amountCollectionRate),
            'tone' => self::rateTone($collectionRate, $expectedCount),
            'amount_tone' => self::rateTone($amountCollectionRate, $expectedAmount > 0 ? 1 : 0),
            'subtitle' => self::collectionSubtitle($posted, $expectedCount, $postedAmount, $expectedAmount),
            'tooltip' => self::collectionTooltip(
                $posted,
                $expectedCount,
                $postedAmount,
                $expectedAmount,
                $collectionRate,
                $amountCollectionRate,
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildWorkflowMonthRow(
        string $label,
        int $total,
        int $successCount,
        int $decidedCount,
    ): array {
        $successRate = $total > 0 ? (int) round(($successCount / $total) * 100) : 0;
        $decidedRate = $total > 0 ? (int) round(($decidedCount / $total) * 100) : 0;

        return [
            'label' => $label,
            'posted' => $successCount,
            'posted_amount' => (float) $decidedCount,
            'expected_count' => $total,
            'expected_amount' => (float) $total,
            'collection_rate' => $successRate,
            'collection_rate_bar' => min(100, $successRate),
            'amount_collection_rate' => $decidedRate,
            'amount_collection_rate_bar' => min(100, $decidedRate),
            'tone' => self::rateTone($successRate, $total),
            'amount_tone' => self::rateTone($decidedRate, $total),
            'subtitle' => $total > 0
                ? $successCount.'/'.$total.' · '.$decidedCount.'/'.$total
                : '—',
            'tooltip' => $total > 0
                ? __(':success% success · :decided% decided · :success_count/:total', [
                    'success' => $successRate,
                    'decided' => $decidedRate,
                    'success_count' => $successCount,
                    'total' => $total,
                ])
                : __('No activity'),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildVolumeMonthRow(
        string $label,
        float $primaryValue,
        float $primaryMax,
        float $secondaryValue,
        float $secondaryMax,
        string $primarySubtitle,
        string $secondarySubtitle,
    ): array {
        $primaryRate = $primaryMax > 0 ? (int) round(($primaryValue / $primaryMax) * 100) : 0;
        $secondaryRate = $secondaryMax > 0 ? (int) round(($secondaryValue / $secondaryMax) * 100) : 0;

        return [
            'label' => $label,
            'posted' => (int) round($primaryValue),
            'posted_amount' => $secondaryValue,
            'expected_count' => (int) round($primaryMax),
            'expected_amount' => $secondaryMax,
            'collection_rate' => $primaryRate,
            'collection_rate_bar' => min(100, $primaryRate),
            'amount_collection_rate' => $secondaryRate,
            'amount_collection_rate_bar' => min(100, $secondaryRate),
            'tone' => self::rateTone($primaryRate, $primaryMax > 0 ? 1 : 0),
            'amount_tone' => self::rateTone($secondaryRate, $secondaryMax > 0 ? 1 : 0),
            'subtitle' => $primarySubtitle.' · '.$secondarySubtitle,
            'tooltip' => $primarySubtitle.' · '.$secondarySubtitle,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $months
     * @return list<array<string, mixed>>
     */
    public static function mapWorkflowTrend(
        array $months,
        string $successKey = 'accepted',
        string $rejectedKey = 'rejected',
    ): array {
        return array_map(
            fn (array $month): array => self::buildWorkflowMonthRow(
                (string) $month['label'],
                (int) $month['total'],
                (int) ($month[$successKey] ?? 0),
                (int) ($month[$successKey] ?? 0) + (int) ($month[$rejectedKey] ?? 0),
            ),
            $months,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $months
     * @return list<array<string, mixed>>
     */
    public static function mapVolumeTrend(
        array $months,
        string $primaryKey,
        string $secondaryKey,
    ): array {
        $primaryMax = max(1.0, (float) collect($months)->max($primaryKey));
        $secondaryMax = max(1.0, (float) collect($months)->max($secondaryKey));

        return array_map(
            fn (array $month): array => self::buildVolumeMonthRow(
                (string) $month['label'],
                (float) $month[$primaryKey],
                $primaryMax,
                (float) $month[$secondaryKey],
                $secondaryMax,
                InsightFormatter::compactAmount((float) $month[$primaryKey]),
                InsightFormatter::compactAmount((float) $month[$secondaryKey]),
            ),
            $months,
        );
    }

    /**
     * @param  list<array<string, mixed>>  $months
     * @return list<array<string, mixed>>
     */
    public static function mapCountTrend(array $months, string $countKey = 'total'): array
    {
        $max = max(1.0, (float) collect($months)->max($countKey));

        return array_map(
            fn (array $month): array => self::buildVolumeMonthRow(
                (string) $month['label'],
                (float) $month[$countKey],
                $max,
                (float) $month[$countKey],
                $max,
                (string) (int) $month[$countKey],
                (string) (int) $month[$countKey],
            ),
            $months,
        );
    }

    private static function collectionSubtitle(
        int $posted,
        int $expectedCount,
        float $postedAmount,
        float $expectedAmount,
    ): string {
        $membersPart = $expectedCount > 0
            ? $posted.'/'.$expectedCount
            : ($posted > 0 ? (string) $posted : '—');

        if ($expectedAmount <= 0 && $postedAmount <= 0) {
            return $membersPart;
        }

        $amountPart = $expectedAmount > 0
            ? InsightFormatter::compactAmount($postedAmount).'/'.InsightFormatter::compactAmount($expectedAmount)
            : InsightFormatter::compactAmount($postedAmount);

        return $membersPart.' · '.$amountPart;
    }

    private static function collectionTooltip(
        int $posted,
        int $expectedCount,
        float $postedAmount,
        float $expectedAmount,
        int $collectionRate,
        int $amountCollectionRate,
    ): string {
        if ($expectedCount === 0) {
            return $posted > 0
                ? __(':count posted · :amount', [
                    'count' => $posted,
                    'amount' => InsightFormatter::money($postedAmount),
                ])
                : __('No expected collections');
        }

        return __(':rate% members · :amount_rate% collected · :posted/:expected · :collected/:expected_amount', [
            'rate' => $collectionRate,
            'amount_rate' => $amountCollectionRate,
            'posted' => $posted,
            'expected' => $expectedCount,
            'collected' => InsightFormatter::money($postedAmount),
            'expected_amount' => InsightFormatter::money($expectedAmount),
        ]);
    }
}
