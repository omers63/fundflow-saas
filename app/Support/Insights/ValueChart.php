<?php

declare(strict_types=1);

namespace App\Support\Insights;

/**
 * Normalised chart payload shapes for CSS-only value charts (no JS chart libraries).
 */
final class ValueChart
{
    /**
     * @param  list<array{key: string, label: string, value: float|int, color?: string, tone?: string}>  $segments
     * @return array<string, mixed>
     */
    public static function donut(string $title, array $segments, ?string $subtitle = null, ?string $center = null): array
    {
        $total = array_sum(array_map(static fn (array $s): float => (float) ($s['value'] ?? 0), $segments));
        $enriched = [];
        $cursor = 0.0;

        foreach ($segments as $segment) {
            $value = max(0.0, (float) ($segment['value'] ?? 0));
            $share = $total > 0 ? round(($value / $total) * 100, 1) : 0.0;
            $enriched[] = [
                ...$segment,
                'value' => $value,
                'share' => $share,
                'offset' => $cursor,
            ];
            $cursor += $share;
        }

        return [
            'type' => 'donut',
            'title' => $title,
            'subtitle' => $subtitle,
            'center' => $center ?? ($total > 0 ? (string) (int) round($total) : '—'),
            'total' => $total,
            'segments' => $enriched,
        ];
    }

    /**
     * @param  list<array{key: string, label: string, value: float|int, color?: string}>  $segments
     * @return array<string, mixed>
     */
    public static function stack(string $title, array $segments, ?string $subtitle = null): array
    {
        $total = array_sum(array_map(static fn (array $s): float => (float) ($s['value'] ?? 0), $segments));
        $enriched = [];

        foreach ($segments as $segment) {
            $value = max(0.0, (float) ($segment['value'] ?? 0));
            $enriched[] = [
                ...$segment,
                'value' => $value,
                'share' => $total > 0 ? round(($value / $total) * 100, 1) : 0.0,
            ];
        }

        return [
            'type' => 'stack',
            'title' => $title,
            'subtitle' => $subtitle,
            'total' => $total,
            'segments' => $enriched,
        ];
    }

    /**
     * @param  list<array{key: string, label: string, value: float|int, sub?: string, color?: string}>  $rows
     * @return array<string, mixed>
     */
    public static function bars(string $title, array $rows, ?string $subtitle = null, bool $asMoney = false): array
    {
        $max = max(1.0, ...array_map(static fn (array $r): float => abs((float) ($r['value'] ?? 0)), $rows ?: [['value' => 0]]));

        $enriched = [];
        foreach ($rows as $row) {
            $value = (float) ($row['value'] ?? 0);
            $enriched[] = [
                ...$row,
                'value' => $value,
                'width' => round(min(100.0, (abs($value) / $max) * 100), 1),
            ];
        }

        return [
            'type' => 'bars',
            'title' => $title,
            'subtitle' => $subtitle,
            'as_money' => $asMoney,
            'rows' => $enriched,
            'max' => $max,
        ];
    }

    /**
     * @param  list<array{key: string, label: string, value: float|int, color?: string}>  $steps
     * @return array<string, mixed>
     */
    public static function funnel(string $title, array $steps, ?string $subtitle = null): array
    {
        $max = max(1.0, ...array_map(static fn (array $s): float => (float) ($s['value'] ?? 0), $steps ?: [['value' => 0]]));

        $enriched = [];
        foreach ($steps as $step) {
            $value = max(0.0, (float) ($step['value'] ?? 0));
            $enriched[] = [
                ...$step,
                'value' => $value,
                'width' => round(min(100.0, ($value / $max) * 100), 1),
            ];
        }

        return [
            'type' => 'funnel',
            'title' => $title,
            'subtitle' => $subtitle,
            'steps' => $enriched,
            'max' => $max,
        ];
    }

    /**
     * @param  list<array{key: string, label: string, count: int, amount: float, color?: string}>  $buckets
     * @return array<string, mixed>
     */
    public static function aging(string $title, array $buckets, ?string $subtitle = null): array
    {
        $maxAmount = max(1.0, ...array_map(static fn (array $b): float => (float) ($b['amount'] ?? 0), $buckets ?: [['amount' => 0]]));

        $enriched = [];
        foreach ($buckets as $bucket) {
            $amount = max(0.0, (float) ($bucket['amount'] ?? 0));
            $enriched[] = [
                ...$bucket,
                'amount' => $amount,
                'count' => (int) ($bucket['count'] ?? 0),
                'width' => round(min(100.0, ($amount / $maxAmount) * 100), 1),
            ];
        }

        return [
            'type' => 'aging',
            'title' => $title,
            'subtitle' => $subtitle,
            'buckets' => $enriched,
            'max_amount' => $maxAmount,
        ];
    }

    /**
     * Multi-point absolute bars for runway-style “levels”.
     *
     * @param  list<array{key: string, label: string, value: float, color?: string}>  $points
     * @return array<string, mixed>
     */
    public static function levels(string $title, array $points, ?string $subtitle = null): array
    {
        $max = max(1.0, ...array_map(static fn (array $p): float => abs((float) ($p['value'] ?? 0)), $points ?: [['value' => 0]]));

        $enriched = [];
        foreach ($points as $point) {
            $value = (float) ($point['value'] ?? 0);
            $enriched[] = [
                ...$point,
                'value' => $value,
                'height' => round(min(100.0, (abs($value) / $max) * 100), 1),
            ];
        }

        return [
            'type' => 'levels',
            'title' => $title,
            'subtitle' => $subtitle,
            'points' => $enriched,
            'max' => $max,
        ];
    }
}
