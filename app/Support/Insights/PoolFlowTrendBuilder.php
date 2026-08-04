<?php

declare(strict_types=1);

namespace App\Support\Insights;

use App\Models\Tenant\CashOutRequest;
use App\Models\Tenant\Contribution;
use App\Models\Tenant\ExpenseDisbursement;
use App\Models\Tenant\FeeDisbursement;
use App\Models\Tenant\InvestDisbursement;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanRepayment;
use App\Services\ContributionCycleService;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;

/**
 * Builds the admin dashboard 12-cycle pool flow chart (inflows vs outflows).
 */
final class PoolFlowTrendBuilder
{
    public const CYCLE_COUNT = 12;

    /** Super-linear bar height so cycle-to-cycle differences read more clearly. */
    public const EXPONENTIAL_ALPHA = 2.25;

    /**
     * Map a raw amount onto a 0–100 visual height using an exponential curve.
     */
    public static function exponentialHeight(float $value, float $max): float
    {
        if ($value <= 0.0 || $max <= 0.0) {
            return 0.0;
        }

        $ratio = min(1.0, $value / $max);
        $alpha = self::EXPONENTIAL_ALPHA;
        $scaled = (exp($alpha * $ratio) - 1.0) / (exp($alpha) - 1.0);

        return round($scaled * 100, 2);
    }

    /**
     * @return array{
     *     points: list<array<string, mixed>>,
     *     max: float,
     *     inflow_series: list<array{key: string, label: string, color: string}>,
     *     outflow_series: list<array{key: string, label: string, color: string}>,
     *     lines: array{inflow: array<string, string>, outflow: array<string, string>}
     * }
     */
    public static function twelveCycles(?ContributionCycleService $cycles = null): array
    {
        $cycles ??= app(ContributionCycleService::class);
        [$openMonth, $openYear] = $cycles->currentOpenPeriod();
        $openCursor = Carbon::create($openYear, $openMonth, 1)->startOfMonth();

        $inflowKeys = ['contributions', 'emi'];
        $outflowKeys = ['loans', 'cash_outs', 'reserves'];

        /** @var list<array{key: string, month: int, year: int, label: string, start: Carbon, end: Carbon}> $periodMetas */
        $periodMetas = [];

        for ($i = self::CYCLE_COUNT - 1; $i >= 0; $i--) {
            $monthStart = $openCursor->copy()->subMonthsNoOverflow($i);
            $m = (int) $monthStart->month;
            $y = (int) $monthStart->year;
            $start = $cycles->cycleStartAt($m, $y)->startOfDay();
            $end = $cycles->cycleDueEndAt($m, $y)->endOfDay();

            $periodMetas[] = [
                'key' => Contribution::periodDate($m, $y),
                'month' => $m,
                'year' => $y,
                'label' => $monthStart->locale(app()->getLocale())->translatedFormat('M'),
                'start' => $start,
                'end' => $end,
            ];
        }

        $byPeriod = [];
        foreach ($periodMetas as $meta) {
            $byPeriod[$meta['key']] = [
                'contributions' => 0.0,
                'emi' => 0.0,
                'loans' => 0.0,
                'cash_outs' => 0.0,
                'reserves' => 0.0,
            ];
        }

        $windowStart = $periodMetas[0]['start'];
        $windowEnd = $periodMetas[array_key_last($periodMetas)]['end'];

        // Contributions belong to a labelled cycle via their period field.
        Contribution::query()
            ->posted()
            ->where('period', '>=', $periodMetas[0]['key'])
            ->where('period', '<=', $periodMetas[array_key_last($periodMetas)]['key'])
            ->selectRaw('period, SUM(COALESCE(amount, 0)) as total')
            ->groupBy('period')
            ->get()
            ->each(function ($row) use (&$byPeriod): void {
                $periodKey = Contribution::normalizePeriodKey($row->period) ?? (string) $row->period;
                if (! array_key_exists($periodKey, $byPeriod)) {
                    return;
                }
                $byPeriod[$periodKey]['contributions'] = round((float) $row->total, 2);
            });

        self::addWindowedSeries(
            $byPeriod,
            $periodMetas,
            self::timestampedSums(
                LoanRepayment::query()
                    ->whereNotNull('paid_at')
                    ->whereBetween('paid_at', [$windowStart, $windowEnd]),
                'paid_at',
                'COALESCE(amount, 0)',
            ),
            'emi',
        );

        self::addWindowedSeries(
            $byPeriod,
            $periodMetas,
            self::timestampedSums(
                Loan::query()
                    ->whereNotNull('disbursed_at')
                    ->whereBetween('disbursed_at', [$windowStart, $windowEnd]),
                'disbursed_at',
                'COALESCE(NULLIF(amount_disbursed, 0), amount, 0)',
            ),
            'loans',
        );

        if (Schema::hasTable('cash_out_requests')) {
            self::addWindowedSeries(
                $byPeriod,
                $periodMetas,
                self::timestampedSums(
                    CashOutRequest::query()
                        ->where('status', 'accepted')
                        ->whereNotNull('reviewed_at')
                        ->whereBetween('reviewed_at', [$windowStart, $windowEnd]),
                    'reviewed_at',
                    'COALESCE(amount, 0)',
                ),
                'cash_outs',
            );
        }

        $reserveQueries = [];
        if (Schema::hasTable('expense_disbursements')) {
            $reserveQueries[] = self::timestampedSums(
                ExpenseDisbursement::query()->whereBetween('transacted_at', [$windowStart, $windowEnd]),
                'transacted_at',
                'COALESCE(amount, 0)',
            );
        }
        if (Schema::hasTable('fee_disbursements')) {
            $reserveQueries[] = self::timestampedSums(
                FeeDisbursement::query()->whereBetween('transacted_at', [$windowStart, $windowEnd]),
                'transacted_at',
                'COALESCE(amount, 0)',
            );
        }
        if (Schema::hasTable('invest_disbursements')) {
            $reserveQueries[] = self::timestampedSums(
                InvestDisbursement::query()->whereBetween('transacted_at', [$windowStart, $windowEnd]),
                'transacted_at',
                'COALESCE(amount, 0)',
            );
        }

        foreach ($reserveQueries as $series) {
            self::addWindowedSeries($byPeriod, $periodMetas, $series, 'reserves');
        }

        $max = 0.0;
        foreach ($byPeriod as $amounts) {
            foreach ($amounts as $amount) {
                $max = max($max, (float) $amount);
            }
        }
        $max = max(1.0, $max);

        $inflowSeries = [
            ['key' => 'contributions', 'label' => __('Contributions'), 'color' => 'sky'],
            ['key' => 'emi', 'label' => __('EMI repayments'), 'color' => 'emerald'],
        ];
        $outflowSeries = [
            ['key' => 'loans', 'label' => __('Loan disbursements'), 'color' => 'amber'],
            ['key' => 'cash_outs', 'label' => __('Cash-outs'), 'color' => 'rose'],
            ['key' => 'reserves', 'label' => __('Reserve outs'), 'color' => 'violet'],
        ];

        $points = [];
        $n = count($periodMetas);
        $index = 0;

        /** @var array<string, list<array{x: float, y: float}>> $inflowLinePoints */
        $inflowLinePoints = array_fill_keys($inflowKeys, []);
        /** @var array<string, list<array{x: float, y: float}>> $outflowLinePoints */
        $outflowLinePoints = array_fill_keys($outflowKeys, []);

        foreach ($periodMetas as $meta) {
            $amounts = $byPeriod[$meta['key']];
            $x = $n > 0 ? (($index + 0.5) / $n) * 100 : 50;

            $inHeights = [];
            foreach ($inflowKeys as $key) {
                $value = (float) $amounts[$key];
                $height = self::exponentialHeight($value, $max);
                $inHeights[$key] = $height;
                $inflowLinePoints[$key][] = [
                    'x' => round($x, 3),
                    'y' => round(50 - ($height * 0.48), 3),
                ];
            }

            $outHeights = [];
            foreach ($outflowKeys as $key) {
                $value = (float) $amounts[$key];
                $height = self::exponentialHeight($value, $max);
                $outHeights[$key] = $height;
                $outflowLinePoints[$key][] = [
                    'x' => round($x, 3),
                    'y' => round(50 + ($height * 0.48), 3),
                ];
            }

            $points[] = [
                'period' => $meta['key'],
                'month' => $meta['month'],
                'year' => $meta['year'],
                'label' => $meta['label'],
                'in' => [
                    'contributions' => round((float) $amounts['contributions'], 2),
                    'emi' => round((float) $amounts['emi'], 2),
                ],
                'out' => [
                    'loans' => round((float) $amounts['loans'], 2),
                    'cash_outs' => round((float) $amounts['cash_outs'], 2),
                    'reserves' => round((float) $amounts['reserves'], 2),
                ],
                'in_heights' => $inHeights,
                'out_heights' => $outHeights,
            ];

            $index++;
        }

        return [
            'points' => $points,
            'max' => round($max, 2),
            'inflow_series' => $inflowSeries,
            'outflow_series' => $outflowSeries,
            'lines' => [
                'inflow' => self::polylineAttributes($inflowLinePoints),
                'outflow' => self::polylineAttributes($outflowLinePoints),
            ],
        ];
    }

    /**
     * @deprecated Use {@see twelveCycles()}
     *
     * @return array<string, mixed>
     */
    public static function thirtyDay(?ContributionCycleService $cycles = null): array
    {
        return self::twelveCycles($cycles);
    }

    /**
     * @param  array<string, list<array{x: float, y: float}>>  $seriesPoints
     * @return array<string, string>
     */
    private static function polylineAttributes(array $seriesPoints): array
    {
        $lines = [];

        foreach ($seriesPoints as $key => $coords) {
            $lines[$key] = collect($coords)
                ->map(fn (array $p): string => $p['x'].','.$p['y'])
                ->implode(' ');
        }

        return $lines;
    }

    /**
     * @param  Builder<Model>  $query
     * @return list<array{at: Carbon, amount: float}>
     */
    private static function timestampedSums($query, string $dateColumn, string $amountExpression): array
    {
        return $query
            ->selectRaw("{$dateColumn} as occurred_at, {$amountExpression} as amount")
            ->get()
            ->map(fn ($row): array => [
                'at' => Carbon::parse((string) $row->occurred_at),
                'amount' => (float) $row->amount,
            ])
            ->all();
    }

    /**
     * @param  array<string, array<string, float>>  $byPeriod
     * @param  list<array{key: string, start: Carbon, end: Carbon}>  $periodMetas
     * @param  list<array{at: Carbon, amount: float}>  $rows
     */
    private static function addWindowedSeries(
        array &$byPeriod,
        array $periodMetas,
        array $rows,
        string $key,
    ): void {
        foreach ($rows as $row) {
            $at = $row['at']->copy();
            foreach ($periodMetas as $meta) {
                if ($at->greaterThanOrEqualTo($meta['start']) && $at->lessThanOrEqualTo($meta['end'])) {
                    $byPeriod[$meta['key']][$key] = round(
                        ($byPeriod[$meta['key']][$key] ?? 0.0) + (float) $row['amount'],
                        2,
                    );
                    break;
                }
            }
        }
    }
}
