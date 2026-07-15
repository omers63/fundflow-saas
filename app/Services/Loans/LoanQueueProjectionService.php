<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\Account;
use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanInstallment;
use App\Models\Tenant\Transaction;
use App\Services\ContributionCycleService;
use App\Support\BusinessDay;
use Illuminate\Database\Eloquent\Collection;

/**
 * Projects how long a queued or pending loan will wait before it can be funded.
 *
 * Primary estimate: expected monthly inflows (open-period contribution targets +
 * EMI repayments falling due), allocated to the loan's fund tier by its percentage.
 * Sanity band: trailing 3-month net master-fund growth, allocated the same way.
 *
 * Results are memoized per service instance — reuse one instance per request.
 */
class LoanQueueProjectionService
{
    private const MAX_MONTHS_DISPLAY = 6;

    /** @var array<int, FundTier> */
    private array $tiers = [];

    /** @var array<int, list<array{id: int, need: float}>> Queued (approved/partial) remaining per tier, in queue order. */
    private array $queuedByTier = [];

    /** @var array<int, list<array{id: int, need: float}>> Pending intake per expected tier, emergencies first then FIFO. */
    private array $pendingByTier = [];

    private ?float $expectedMonthlyInflow = null;

    private ?float $historicalMonthlyInflow = null;

    public function __construct(private ContributionCycleService $cycles) {}

    /**
     * @return array{ready_now: bool, months_min: int|null, months_max: int|null, label: string}
     */
    public function projectionFor(Loan $loan): array
    {
        $tier = $this->resolveTier($loan);

        if ($tier === null) {
            return $this->result(false, null, null);
        }

        $shortfall = $this->shortfallFor($loan, $tier);

        if ($shortfall <= 0.01) {
            return $this->result(true, 0, 0);
        }

        $months = [];

        $primary = $this->expectedMonthlyInflow() * ((float) $tier->percentage / 100);
        if ($primary >= 0.01) {
            $months[] = (int) ceil($shortfall / $primary);
        }

        $band = $this->historicalMonthlyInflow() * ((float) $tier->percentage / 100);
        if ($band >= 0.01) {
            $months[] = (int) ceil($shortfall / $band);
        }

        if ($months === []) {
            return $this->result(false, null, null);
        }

        return $this->result(false, min($months), max($months));
    }

    public function labelFor(Loan $loan): string
    {
        return $this->projectionFor($loan)['label'];
    }

    /**
     * Pool shortfall for this loan after funding everything ahead of it in its tier.
     */
    private function shortfallFor(Loan $loan, FundTier $tier): float
    {
        $pool = (float) $tier->disbursable_pool;

        $ahead = 0.0;
        $queued = $this->queuedForTier($tier);

        if ($loan->status === 'pending') {
            $ahead += array_sum(array_column($queued, 'need'));

            foreach ($this->pendingForTier($tier) as $row) {
                if ($row['id'] === (int) $loan->id) {
                    break;
                }
                $ahead += $row['need'];
            }

            $ownNeed = (float) ($loan->amount_approved ?? $loan->amount_requested);
        } else {
            foreach ($queued as $row) {
                if ($row['id'] === (int) $loan->id) {
                    break;
                }
                $ahead += $row['need'];
            }

            $ownNeed = $loan->remainingToDisburse();
        }

        return $ahead + $ownNeed - $pool;
    }

    private function resolveTier(Loan $loan): ?FundTier
    {
        if ($loan->fund_tier_id !== null) {
            return $this->tier((int) $loan->fund_tier_id);
        }

        $tier = FundTier::resolveForLoan($loan);

        return $tier !== null ? $this->tier((int) $tier->id, $tier) : null;
    }

    private function tier(int $id, ?FundTier $loaded = null): ?FundTier
    {
        return $this->tiers[$id] ??= ($loaded ?? FundTier::query()->find($id));
    }

    /**
     * @return list<array{id: int, need: float}>
     */
    private function queuedForTier(FundTier $tier): array
    {
        return $this->queuedByTier[$tier->id] ??= Loan::query()
            ->where('fund_tier_id', $tier->id)
            ->whereIn('status', ['approved', 'partially_disbursed'])
            ->orderByRaw('queue_position IS NULL, queue_position')
            ->orderBy('applied_at')
            ->orderBy('id')
            ->get(['id', 'amount_approved', 'amount_disbursed'])
            ->map(fn (Loan $l): array => ['id' => (int) $l->id, 'need' => $l->remainingToDisburse()])
            ->all();
    }

    /**
     * @return list<array{id: int, need: float}>
     */
    private function pendingForTier(FundTier $tier): array
    {
        if (! array_key_exists($tier->id, $this->pendingByTier)) {
            /** @var Collection<int, Loan> $pending */
            $pending = Loan::query()
                ->where('status', 'pending')
                ->with('loanTier')
                ->orderByDesc('is_emergency')
                ->orderBy('applied_at')
                ->orderBy('id')
                ->get();

            $byTier = [];
            foreach ($pending as $loan) {
                $expected = FundTier::resolveForLoan($loan);
                if ($expected === null) {
                    continue;
                }
                $byTier[$expected->id][] = [
                    'id' => (int) $loan->id,
                    'need' => (float) ($loan->amount_approved ?? $loan->amount_requested),
                ];
            }

            foreach (FundTier::query()->where('is_active', true)->pluck('id') as $tierId) {
                $this->pendingByTier[(int) $tierId] = $byTier[(int) $tierId] ?? [];
            }
            $this->pendingByTier[$tier->id] ??= $byTier[$tier->id] ?? [];
        }

        return $this->pendingByTier[$tier->id] ?? [];
    }

    /**
     * Expected monthly inflow into the master fund: open-period contribution
     * targets plus EMI repayments due over the next 3 months (averaged).
     */
    private function expectedMonthlyInflow(): float
    {
        if ($this->expectedMonthlyInflow !== null) {
            return $this->expectedMonthlyInflow;
        }

        [$month, $year] = $this->cycles->currentOpenPeriod();
        $contributions = (float) ($this->cycles->expectedCollectionTargetsForPeriod($month, $year)['expected_amount'] ?? 0);

        $now = BusinessDay::now();
        $emiMonthly = ((float) LoanInstallment::query()
            ->whereIn('status', ['pending', 'overdue'])
            ->whereHas('loan', fn ($q) => $q->where('status', 'active'))
            ->whereBetween('due_date', [$now, $now->copy()->addMonths(3)])
            ->sum('amount')) / 3;

        return $this->expectedMonthlyInflow = $contributions + $emiMonthly;
    }

    /**
     * Trailing 3-month average net growth of the master fund ledger.
     */
    private function historicalMonthlyInflow(): float
    {
        if ($this->historicalMonthlyInflow !== null) {
            return $this->historicalMonthlyInflow;
        }

        $masterFund = Account::masterFund();

        if ($masterFund === null) {
            return $this->historicalMonthlyInflow = 0.0;
        }

        $since = BusinessDay::now()->copy()->subMonths(3);

        $credits = (float) Transaction::query()
            ->where('account_id', $masterFund->id)
            ->credits()
            ->where('transacted_at', '>=', $since)
            ->sum('amount');
        $debits = (float) Transaction::query()
            ->where('account_id', $masterFund->id)
            ->debits()
            ->where('transacted_at', '>=', $since)
            ->sum('amount');

        return $this->historicalMonthlyInflow = max(0.0, ($credits - $debits) / 3);
    }

    /**
     * @return array{ready_now: bool, months_min: int|null, months_max: int|null, label: string}
     */
    private function result(bool $readyNow, ?int $min, ?int $max): array
    {
        return [
            'ready_now' => $readyNow,
            'months_min' => $min,
            'months_max' => $max,
            'label' => $this->label($readyNow, $min, $max),
        ];
    }

    private function label(bool $readyNow, ?int $min, ?int $max): string
    {
        if ($readyNow) {
            return __('Ready now');
        }

        if ($min === null || $max === null) {
            return __('No projected inflow');
        }

        if ($min > self::MAX_MONTHS_DISPLAY) {
            return __('> :count months', ['count' => self::MAX_MONTHS_DISPLAY]);
        }

        $max = min($max, self::MAX_MONTHS_DISPLAY + 1);

        if ($min === $max) {
            return trans_choice('~:count month|~:count months', $min, ['count' => $min]);
        }

        if ($max > self::MAX_MONTHS_DISPLAY) {
            return __(':min+ months', ['min' => $min]);
        }

        return __(':min–:max months', ['min' => $min, 'max' => $max]);
    }
}
