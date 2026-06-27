<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\SystemJobRun;
use App\Services\SystemJobRunnerService;
use Illuminate\Support\Collection;

final class AutomationAreaSummary
{
    /**
     * @return array<string, string>
     */
    public static function areaLabels(): array
    {
        return [
            'contributions' => __('Collections'),
            'loans' => __('Loans'),
            'bank' => __('Bank'),
            'reconciliation' => __('Reconciliation'),
            'statements' => __('Statements & messaging'),
        ];
    }

    /**
     * @return list<string>
     */
    public static function categoriesForArea(string $area): array
    {
        return match ($area) {
            'contributions' => ['contributions'],
            'loans' => ['loans'],
            'bank' => ['bank'],
            'reconciliation' => ['reconciliation', 'fund'],
            'statements' => ['statements', 'messaging'],
            default => [],
        };
    }

    /**
     * @return list<array{
     *     area: string,
     *     label: string,
     *     job_count: int,
     *     last_status: ?string,
     *     failures_last_7_days: int,
     *     schedule_hint: string
     * }>
     */
    public static function summarize(SystemJobRunnerService $runner): array
    {
        $records = $runner->catalogRecords();

        return collect(self::areaLabels())
            ->map(function (string $label, string $area) use ($records): array {
                $categories = self::categoriesForArea($area);
                $areaJobs = $records->filter(
                    fn (array $row): bool => in_array($row['category'], $categories, true),
                );

                $failures = self::failuresInLastSevenDays($areaJobs->pluck('key')->all());

                return [
                    'area' => $area,
                    'label' => $label,
                    'job_count' => $areaJobs->count(),
                    'last_status' => self::worstStatus($areaJobs),
                    'failures_last_7_days' => $failures,
                    'schedule_hint' => self::scheduleHint($areaJobs),
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $areaJobs
     */
    private static function worstStatus(Collection $areaJobs): ?string
    {
        if ($areaJobs->contains(fn (array $row): bool => $row['last_status'] === SystemJobRun::STATUS_FAILED)) {
            return SystemJobRun::STATUS_FAILED;
        }

        if ($areaJobs->contains(fn (array $row): bool => $row['last_status'] === SystemJobRun::STATUS_RUNNING)) {
            return SystemJobRun::STATUS_RUNNING;
        }

        if ($areaJobs->contains(fn (array $row): bool => $row['last_status'] === SystemJobRun::STATUS_SUCCESS)) {
            return SystemJobRun::STATUS_SUCCESS;
        }

        return null;
    }

    /**
     * @param  list<string>  $jobKeys
     */
    private static function failuresInLastSevenDays(array $jobKeys): int
    {
        if ($jobKeys === []) {
            return 0;
        }

        return (int) SystemJobRun::query()
            ->whereIn('job_key', $jobKeys)
            ->where('status', SystemJobRun::STATUS_FAILED)
            ->where('started_at', '>=', now()->subDays(7))
            ->count();
    }

    /**
     * @param  Collection<string, array<string, mixed>>  $areaJobs
     */
    private static function scheduleHint(Collection $areaJobs): string
    {
        $schedules = $areaJobs
            ->pluck('schedule')
            ->filter()
            ->unique()
            ->values();

        if ($schedules->isEmpty()) {
            return __('No scheduled jobs in this area');
        }

        if ($schedules->count() === 1) {
            return (string) $schedules->first();
        }

        return __('Mixed schedules (:count jobs)', ['count' => $areaJobs->count()]);
    }
}
