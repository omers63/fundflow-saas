<?php

declare(strict_types=1);

namespace App\Services\FiscalClose;

use Carbon\Carbon;

final readonly class FiscalYearPeriod
{
    public function __construct(
        public string $label,
        public Carbon $periodStart,
        public Carbon $periodEnd,
    ) {}

    public function periodStartDateString(): string
    {
        return $this->periodStart->toDateString();
    }

    public function periodEndDateString(): string
    {
        return $this->periodEnd->toDateString();
    }
}
