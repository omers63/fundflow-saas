<?php

declare(strict_types=1);

namespace App\Services\LegacyMigration;

use App\Models\Tenant\Loan;

final class LegacyLoanRepaymentInstallmentTracker
{
    private const float AMOUNT_TOLERANCE = 0.02;

    /** @var array<string, float> */
    private array $pendingPoolByLoanKey = [];

    /** @var array<string, int> */
    private array $paidInstallmentCountByLoanKey = [];

    /** @var array<string, array{installment_amount: float, installment_count: int}> */
    private array $scheduleByLoanKey = [];

    public function __construct(
        private readonly LegacyMigrationDatabaseLoanResolver $loanResolver,
    ) {
    }

    public function registerWindow(LegacyLoanRepaymentWindow $window): void
    {
        if (isset($this->scheduleByLoanKey[$window->loanKey])) {
            return;
        }

        $this->scheduleByLoanKey[$window->loanKey] = self::resolveSchedule(
            $window,
            $this->loanResolver,
        );
    }

    public function refreshSchedule(LegacyLoanRepaymentWindow $window): void
    {
        unset($this->scheduleByLoanKey[$window->loanKey]);
        $this->registerWindow($window);
    }

    public function isScheduleSatisfied(string $loanKey): bool
    {
        $schedule = $this->scheduleByLoanKey[$loanKey] ?? null;

        if ($schedule === null || $schedule['installment_count'] <= 0) {
            return false;
        }

        return ($this->paidInstallmentCountByLoanKey[$loanKey] ?? 0) >= $schedule['installment_count'];
    }

    public function remainingScheduleCapacity(string $loanKey): float
    {
        if ($this->isScheduleSatisfied($loanKey)) {
            return 0.0;
        }

        $schedule = $this->scheduleByLoanKey[$loanKey] ?? null;

        if ($schedule === null) {
            return PHP_FLOAT_MAX;
        }

        $remainingInstallments = $schedule['installment_count'] - ($this->paidInstallmentCountByLoanKey[$loanKey] ?? 0);
        $installmentAmount = $schedule['installment_amount'];
        $pendingPool = $this->pendingPoolByLoanKey[$loanKey] ?? 0.0;

        return round(max(0.0, ($remainingInstallments * $installmentAmount) - $pendingPool), 2);
    }

    public function applyRepayment(string $loanKey, float $amount): void
    {
        if ($amount <= self::AMOUNT_TOLERANCE) {
            return;
        }

        $schedule = $this->scheduleByLoanKey[$loanKey] ?? null;

        if ($schedule === null) {
            return;
        }

        $installmentAmount = $schedule['installment_amount'];
        $targetCount = $schedule['installment_count'];
        $pool = round(($this->pendingPoolByLoanKey[$loanKey] ?? 0.0) + $amount, 2);
        $paid = $this->paidInstallmentCountByLoanKey[$loanKey] ?? 0;

        while ($paid < $targetCount && $pool + self::AMOUNT_TOLERANCE >= $installmentAmount) {
            $pool = round($pool - $installmentAmount, 2);
            $paid++;
        }

        $this->pendingPoolByLoanKey[$loanKey] = $pool;
        $this->paidInstallmentCountByLoanKey[$loanKey] = $paid;
    }

    /**
     * @return array{installment_amount: float, installment_count: int}
     */
    public static function resolveSchedule(
        LegacyLoanRepaymentWindow $window,
        ?LegacyMigrationDatabaseLoanResolver $loanResolver = null,
    ): array {
        $loan = self::resolveLoanRecord($window, $loanResolver);

        if ($loan !== null) {
            $persistedInstallmentCount = (int) $loan->installments()->count();

            if ($persistedInstallmentCount > 0) {
                return [
                    'installment_amount' => (float) $loan->installments()->orderBy('installment_number')->value('amount'),
                    'installment_count' => $persistedInstallmentCount,
                ];
            }

            $minInstall = (float) ($loan->loanTier?->min_monthly_installment ?? 0);

            if ($minInstall > self::AMOUNT_TOLERANCE && (int) $loan->installments_count > 0) {
                return [
                    'installment_amount' => $minInstall,
                    'installment_count' => (int) $loan->installments_count,
                ];
            }
        }

        return LegacyLoanRepaymentScheduleEstimate::forWindow($window);
    }

    private static function resolveLoanRecord(
        LegacyLoanRepaymentWindow $window,
        ?LegacyMigrationDatabaseLoanResolver $loanResolver,
    ): ?Loan {
        if ($window->loanId !== null) {
            $loan = Loan::query()->with('loanTier')->find($window->loanId);

            if ($loan !== null) {
                return $loan;
            }
        }

        if ($loanResolver === null || $window->memberNumber === null) {
            return null;
        }

        $resolvedLoanId = $loanResolver->findLoanId($window->memberNumber, $window->disbursedAt);

        if ($resolvedLoanId === null) {
            return null;
        }

        return Loan::query()->with('loanTier')->find($resolvedLoanId);
    }
}
