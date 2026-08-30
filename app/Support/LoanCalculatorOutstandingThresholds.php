<?php

declare(strict_types=1);

namespace App\Support;

/**
 * Outstanding-loan options for the member loan calculator.
 *
 * Settlement adds the current-loan settlement floor to projected fund at start.
 * Eligibility advances the start date until projected fund meets the eligibility floor.
 * Service callers that omit this object keep the legacy projection (remaining
 * installments that fit the window only).
 */
final class LoanCalculatorOutstandingThresholds
{
    public function __construct(
        public readonly bool $settlement = false,
        public readonly bool $eligibility = false,
    ) {}

    public static function none(): self
    {
        return new self(false, false);
    }

    public static function both(): self
    {
        return new self(true, true);
    }

    public function any(): bool
    {
        return $this->settlement || $this->eligibility;
    }
}
