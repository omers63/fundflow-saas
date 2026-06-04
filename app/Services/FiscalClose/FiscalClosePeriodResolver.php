<?php

declare(strict_types=1);

namespace App\Services\FiscalClose;

use App\Support\BusinessDay;
use App\Support\FiscalSettings;
use Carbon\Carbon;
use InvalidArgumentException;

final class FiscalClosePeriodResolver
{
    public function resolvePeriodContaining(?Carbon $date = null): FiscalYearPeriod
    {
        $date ??= BusinessDay::now()->copy()->startOfDay();

        return $this->buildPeriod($this->periodStartForDate($date));
    }

    public function resolvePeriodForLabel(string $label): FiscalYearPeriod
    {
        if (! preg_match('/^FY(\d{4})$/', $label, $matches)) {
            throw new InvalidArgumentException(__('Invalid fiscal year label: :label', ['label' => $label]));
        }

        $endYear = (int) $matches[1];
        $startMonth = FiscalSettings::fiscalYearStartMonth();
        $startDay = FiscalSettings::fiscalYearStartDay();

        if ($startMonth === 1 && $startDay === 1) {
            $periodStart = Carbon::create($endYear, 1, 1)->startOfDay();
        } else {
            $periodStart = Carbon::create($endYear - 1, $startMonth, $startDay)->startOfDay();
        }

        return $this->buildPeriod($periodStart, $label);
    }

    public function nextPeriodAfter(FiscalYearPeriod $closed): FiscalYearPeriod
    {
        $nextStart = $closed->periodEnd->copy()->addDay()->startOfDay();

        return $this->resolvePeriodContaining($nextStart);
    }

    public function assertNotClosed(Carbon $transactedAt): void
    {
        $closedThrough = FiscalSettings::booksClosedThrough();

        if ($closedThrough === null) {
            return;
        }

        if ($transactedAt->copy()->startOfDay()->lte($closedThrough)) {
            throw new InvalidArgumentException(__(
                'Books are closed through :date. Backdated postings are not allowed.',
                ['date' => $closedThrough->toFormattedDateString()],
            ));
        }
    }

    private function periodStartForDate(Carbon $date): Carbon
    {
        $startMonth = FiscalSettings::fiscalYearStartMonth();
        $startDay = FiscalSettings::fiscalYearStartDay();

        $periodStart = Carbon::create($date->year, $startMonth, $startDay)->startOfDay();

        if ($date->lt($periodStart)) {
            $periodStart = $periodStart->subYear();
        }

        return $periodStart;
    }

    private function buildPeriod(Carbon $periodStart, ?string $labelOverride = null): FiscalYearPeriod
    {
        $periodEnd = $periodStart->copy()->addYear()->subDay()->endOfDay();
        $label = $labelOverride ?? ('FY'.$periodEnd->year);

        return new FiscalYearPeriod($label, $periodStart, $periodEnd);
    }
}
