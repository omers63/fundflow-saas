<?php

declare(strict_types=1);

namespace App\Services\FiscalClose;

use Carbon\Carbon;

final readonly class FiscalCloseReadinessReport
{
    /**
     * @param  list<FiscalCloseGateResult>  $gates
     */
    public function __construct(
        public FiscalYearPeriod $period,
        public Carbon $assessedAt,
        public array $gates,
    ) {}

    public function canProceed(): bool
    {
        foreach ($this->gates as $gate) {
            if ($gate->isFail()) {
                return false;
            }
        }

        return true;
    }

    /**
     * @return list<FiscalCloseGateResult>
     */
    public function failingGates(): array
    {
        return array_values(array_filter($this->gates, fn (FiscalCloseGateResult $gate): bool => $gate->isFail()));
    }

    /**
     * @return list<FiscalCloseGateResult>
     */
    public function warningGates(): array
    {
        return array_values(array_filter(
            $this->gates,
            fn (FiscalCloseGateResult $gate): bool => $gate->status === FiscalCloseGateResult::STATUS_WARN,
        ));
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'fiscal_year_label' => $this->period->label,
            'period_start' => $this->period->periodStartDateString(),
            'period_end' => $this->period->periodEndDateString(),
            'assessed_at' => $this->assessedAt->toIso8601String(),
            'can_proceed' => $this->canProceed(),
            'gates' => array_map(fn (FiscalCloseGateResult $gate): array => $gate->toArray(), $this->gates),
        ];
    }
}
