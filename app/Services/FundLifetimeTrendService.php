<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Member;
use App\Support\BusinessDay;
use Illuminate\Support\Facades\Cache;

/**
 * Fund-level twin of the member "Contributions & repayments — lifetime" chart: the same cycle buckets and rules with
 * every member's amounts added together, so the dashboard total always equals the sum of the member charts.
 * Same-day payments are grouped (`count`) to keep the chart pop-ups small.
 */
final class FundLifetimeTrendService
{
    public function __construct(private readonly MemberLifetimeTrendService $members) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function trend(): array
    {
        return Cache::remember(
            'fund_lifetime_trend_v1:'.tenant()?->getTenantKey().':'.BusinessDay::now()->toDateString(),
            now()->addMinutes(30),
            fn (): array => $this->build(),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function build(): array
    {
        // Every member is built once (loans eager-loaded so the exemption checks stay in memory): a few seconds the
        // first time, then served from the 30-minute cache.
        @set_time_limit(180);

        /** @var array<string, array<string, mixed>> $rows */
        $rows = [];
        $sumKeys = ['contributionsPosted', 'contributionsExpected', 'repaymentsPaid', 'repaymentsCount', 'repaymentsDue', 'disbursed'];
        $listKeys = ['contributionsPostedOn', 'repaymentsPaidOn', 'disbursedOn'];

        foreach (Member::query()->with('loans')->get() as $member) {
            foreach ($this->members->buildFresh($member) as $r) {
                $row = &$rows[$r['key']];
                $row ??= ['key' => $r['key'], 'label' => $r['label'], ...array_fill_keys($sumKeys, 0), ...array_fill_keys($listKeys, [])];
                foreach ($sumKeys as $k) {
                    $row[$k] += $r[$k];
                }
                foreach ($listKeys as $k) {
                    foreach ($r[$k] as $e) {
                        $row[$k][] = $e;
                    }
                }
                unset($row);
            }
        }

        ksort($rows);

        return array_values(array_map(function (array $row): array {
            foreach (['contributionsPosted', 'contributionsExpected', 'repaymentsPaid', 'repaymentsDue', 'disbursed'] as $k) {
                $row[$k] = round((float) $row[$k], 2);
            }
            $row['contributionRate'] = $row['contributionsExpected'] > 0 ? (int) round(($row['contributionsPosted'] / $row['contributionsExpected']) * 100) : null;
            $row['repaymentRate'] = $row['repaymentsDue'] > 0 ? (int) round(($row['repaymentsPaid'] / $row['repaymentsDue']) * 100) : null;
            $row['contributionsPostedOn'] = self::group($row['contributionsPostedOn'], false);
            $row['repaymentsPaidOn'] = self::group($row['repaymentsPaidOn'], true);
            $row['disbursedOn'] = array_map(
                fn (array $e): array => ['on' => $e['on'], 'amount' => $e['amount']],
                self::group($row['disbursedOn'], true),
            );

            return $row;
        }, $rows));
    }

    /**
     * Same-day payments grouped as { on, timing, count[, amount] }.
     *
     * @param  list<array<string, mixed>>  $items
     * @return list<array<string, mixed>>
     */
    private static function group(array $items, bool $withAmount): array
    {
        $grouped = [];
        foreach ($items as $e) {
            $k = $e['on'].'|'.($e['timing'] ?? '');
            $grouped[$k] ??= ['on' => $e['on'], 'timing' => $e['timing'] ?? null, 'count' => 0, ...($withAmount ? ['amount' => 0.0] : [])];
            $grouped[$k]['count']++;
            if ($withAmount) {
                $grouped[$k]['amount'] = round($grouped[$k]['amount'] + (float) ($e['amount'] ?? 0), 2);
            }
        }
        ksort($grouped);

        return array_values($grouped);
    }
}
