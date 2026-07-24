<?php

declare(strict_types=1);

namespace App\Support\Reconciliation;

use App\Models\Tenant\FundAuditLog;
use App\Models\Tenant\ReconciliationException;
use App\Models\Tenant\ReconciliationSnapshot;
use Carbon\Carbon;
use Illuminate\Support\Facades\Schema;

final class ReconciliationHealthSummary
{
    public const STATUS_PASS = 'pass';

    public const STATUS_ATTENTION = 'attention';

    public const STATUS_CRITICAL = 'critical';

    /**
     * @return array{
     *     status: string,
     *     status_label: string,
     *     open_issues: int,
     *     critical_issues: int,
     *     warning_issues: int,
     *     pending_bank_clearance: int,
     *     last_checked_at: ?Carbon,
     *     last_checked_label: string,
     *     next_check_at: Carbon,
     *     next_check_label: string
     * }
     */
    public function summarize(
        ?ReconciliationSnapshot $latestSnapshot,
        int $openExceptionCount,
        int $openCriticalCount,
        int $openWarningCount,
        int $pendingBankClearanceCount,
        ?FundAuditLog $lastBatch,
    ): array {
        $status = $this->resolveStatus($latestSnapshot, $openCriticalCount, $openExceptionCount);

        $lastCheckedAt = $this->resolveLastCheckedAt($latestSnapshot, $lastBatch);

        $nextCheckAt = $this->nextBatchRunAt();

        return [
            'status' => $status,
            'status_label' => $this->statusLabel($status),
            'open_issues' => $openExceptionCount,
            'critical_issues' => $openCriticalCount,
            'warning_issues' => $openWarningCount,
            'pending_bank_clearance' => $pendingBankClearanceCount,
            'last_checked_at' => $lastCheckedAt,
            'last_checked_label' => $lastCheckedAt?->diffForHumans() ?? __('Not yet checked'),
            'next_check_at' => $nextCheckAt,
            'next_check_label' => __('Next nightly exception batch: :time', [
                'time' => $nextCheckAt->format('d M Y').' '.__('at').' '.$nextCheckAt->format('H:i'),
            ]),
        ];
    }

    /**
     * @return list<array{label: string, action: string, url: ?string, tab: ?string}>
     */
    public function nextSteps(int $limit = 5): array
    {
        if (! Schema::hasTable('reconciliation_exceptions')) {
            return [];
        }

        $exceptions = ReconciliationException::query()
            ->open()
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'high' THEN 1 WHEN 'medium' THEN 2 ELSE 3 END")
            ->orderByDesc('raised_at')
            ->limit($limit * 3)
            ->get();

        $steps = [];
        $seenDomains = [];

        foreach ($exceptions as $exception) {
            $domain = $exception->domain;

            if (isset($seenDomains[$domain])) {
                continue;
            }

            $seenDomains[$domain] = true;

            $domainCount = ReconciliationException::query()
                ->open()
                ->where('domain', $domain)
                ->count();

            $steps[] = [
                'label' => trans_choice(
                    ':count open :area issue — :action|:count open :area issues — :action',
                    $domainCount,
                    [
                        'count' => $domainCount,
                        'area' => ReconciliationExceptionPresenter::domainLabel($domain),
                        'action' => ReconciliationExceptionPresenter::recommendedAction($exception),
                    ],
                ),
                'action' => ReconciliationExceptionPresenter::recommendedAction($exception),
                'url' => ReconciliationExceptionPresenter::isBankClearingRelated($exception)
                    ? ReconciliationExceptionPresenter::bankClearingUrl($exception)
                    : null,
                'tab' => ReconciliationExceptionPresenter::isBankClearingRelated($exception) ? null : 'exceptions',
            ];

            if (count($steps) >= $limit) {
                break;
            }
        }

        return $steps;
    }

    public function openCriticalCount(): int
    {
        if (! Schema::hasTable('reconciliation_exceptions')) {
            return 0;
        }

        return (int) ReconciliationException::query()
            ->open()
            ->where('severity', 'critical')
            ->count();
    }

    public function openWarningCount(): int
    {
        if (! Schema::hasTable('reconciliation_exceptions')) {
            return 0;
        }

        return (int) ReconciliationException::query()
            ->open()
            ->whereIn('severity', ['high', 'medium', 'low'])
            ->count();
    }

    public function nextBatchRunAt(): Carbon
    {
        $tz = config('app.timezone');
        $next = Carbon::now($tz)->setTime(6, 30);

        if ($next->isPast()) {
            $next->addDay();
        }

        return $next;
    }

    private function resolveStatus(?ReconciliationSnapshot $latestSnapshot, int $openCriticalCount, int $openExceptionCount): string
    {
        if ($openCriticalCount > 0) {
            return self::STATUS_CRITICAL;
        }

        if ($openExceptionCount > 0 || ($latestSnapshot !== null && ! $latestSnapshot->is_passing)) {
            return self::STATUS_ATTENTION;
        }

        if ($latestSnapshot !== null && $latestSnapshot->is_passing) {
            return self::STATUS_PASS;
        }

        return self::STATUS_ATTENTION;
    }

    private function statusLabel(string $status): string
    {
        return match ($status) {
            self::STATUS_PASS => __('In balance'),
            self::STATUS_CRITICAL => __('Critical attention needed'),
            default => __('Needs attention'),
        };
    }

    private function resolveLastCheckedAt(?ReconciliationSnapshot $latestSnapshot, ?FundAuditLog $lastBatch): ?Carbon
    {
        $snapshotAt = $latestSnapshot?->as_of;
        $batchAt = $lastBatch?->occurred_at;

        if ($snapshotAt === null) {
            return $batchAt;
        }

        if ($batchAt === null) {
            return $snapshotAt;
        }

        return $snapshotAt->greaterThan($batchAt) ? $snapshotAt : $batchAt;
    }
}
