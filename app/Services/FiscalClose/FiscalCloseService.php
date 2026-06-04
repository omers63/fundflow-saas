<?php

declare(strict_types=1);

namespace App\Services\FiscalClose;

use App\Models\Tenant\FiscalClose;
use App\Models\Tenant\User;
use App\Support\BusinessDay;
use App\Support\FiscalSettings;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class FiscalCloseService
{
    public function __construct(
        protected FiscalClosePeriodResolver $periodResolver,
        protected FiscalCloseReadinessService $readiness,
        protected FiscalCloseSnapshotService $snapshot,
        protected FiscalCloseRollForwardService $rollForward,
        protected FiscalClosePurgeService $purge,
        protected FiscalCloseExportService $exports,
    ) {}

    public function findOrStartDraft(string $fiscalYearLabel, Carbon $periodEnd): FiscalClose
    {
        $inProgress = FiscalClose::query()
            ->where('fiscal_year_label', $fiscalYearLabel)
            ->whereNotIn('status', [
                FiscalClose::STATUS_FAILED,
                FiscalClose::STATUS_ROLLED_FORWARD,
                FiscalClose::STATUS_PURGED,
            ])
            ->latest('id')
            ->first();

        if ($inProgress !== null) {
            return $inProgress;
        }

        return $this->startDraft($fiscalYearLabel, $periodEnd);
    }

    public function startDraft(string $fiscalYearLabel, Carbon $periodEnd): FiscalClose
    {
        $period = $this->periodResolver->resolvePeriodForLabel($fiscalYearLabel);
        $periodEnd = $periodEnd->copy()->startOfDay();

        if ($periodEnd->lt($period->periodStart) || $periodEnd->gt($period->periodEnd)) {
            throw new InvalidArgumentException(__('Period end must fall within :label (:start – :end).', [
                'label' => $period->label,
                'start' => $period->periodStart->toFormattedDateString(),
                'end' => $period->periodEnd->toFormattedDateString(),
            ]));
        }

        $completed = FiscalClose::query()
            ->where('fiscal_year_label', $fiscalYearLabel)
            ->whereIn('status', [FiscalClose::STATUS_ROLLED_FORWARD, FiscalClose::STATUS_PURGED])
            ->exists();

        if ($completed) {
            throw new InvalidArgumentException(__(':label has already been closed.', [
                'label' => $fiscalYearLabel,
            ]));
        }

        $closedThrough = FiscalSettings::booksClosedThrough();
        if ($closedThrough !== null && $periodEnd->lte($closedThrough)) {
            throw new InvalidArgumentException(__('Books are already closed through :date.', [
                'date' => $closedThrough->toFormattedDateString(),
            ]));
        }

        return FiscalClose::query()->create([
            'fiscal_year_label' => $fiscalYearLabel,
            'period_start' => $period->periodStart->toDateString(),
            'period_end' => $periodEnd->toDateString(),
            'status' => FiscalClose::STATUS_DRAFT,
        ]);
    }

    public function runValidation(FiscalClose $close): FiscalCloseReadinessReport
    {
        $close->update(['status' => FiscalClose::STATUS_VALIDATING]);

        $report = $this->readiness->assess(
            $close->period_end->copy()->startOfDay(),
            $close->fiscal_year_label,
        );

        $close->update([
            'status' => FiscalClose::STATUS_DRAFT,
            'readiness_report_json' => $report->toArray(),
            'failure_reason' => $report->canProceed()
                ? null
                : __('One or more readiness gates failed.'),
        ]);

        return $report;
    }

    public function prepareSnapshot(string $fiscalYearLabel, Carbon $periodEnd, User $operator): FiscalClose
    {
        $close = $this->findOrStartDraft($fiscalYearLabel, $periodEnd);
        $report = $this->runValidation($close);

        if (! $report->canProceed()) {
            throw new InvalidArgumentException(__('Readiness checks must pass before building a snapshot.'));
        }

        return DB::transaction(function () use ($close, $operator): FiscalClose {
            $close->update([
                'closed_by' => $operator->id,
                'closed_at' => BusinessDay::now(),
            ]);

            return $this->snapshot->build($close);
        });
    }

    public function buildSnapshot(FiscalClose $close, User $operator): FiscalClose
    {
        if (($close->readiness_report_json['can_proceed'] ?? false) !== true) {
            throw new InvalidArgumentException(__('Readiness checks must pass before building a snapshot.'));
        }

        return DB::transaction(function () use ($close, $operator): FiscalClose {
            $close->update([
                'closed_by' => $operator->id,
                'closed_at' => BusinessDay::now(),
            ]);

            return $this->snapshot->build($close);
        });
    }

    public function approveAndRollForward(FiscalClose $close, User $approver): FiscalClose
    {
        return $this->rollForward->execute($close, $approver);
    }

    /**
     * @return array<string, int|string>
     */
    public function generateExports(FiscalClose $close): array
    {
        return $this->exports->generateAll($close);
    }

    /**
     * @return array<string, int|string>
     */
    public function executeTierAPurge(FiscalClose $close): array
    {
        return $this->purge->executeTierA($close);
    }

    /**
     * @return array<string, int|string>
     */
    public function executeTierBPurge(FiscalClose $close): array
    {
        return $this->purge->executeTierB($close);
    }

    public function latestForLabel(string $fiscalYearLabel): ?FiscalClose
    {
        return FiscalClose::query()
            ->where('fiscal_year_label', $fiscalYearLabel)
            ->whereNotIn('status', [FiscalClose::STATUS_FAILED])
            ->latest('id')
            ->first();
    }
}
