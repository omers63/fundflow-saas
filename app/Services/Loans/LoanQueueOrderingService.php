<?php

declare(strict_types=1);

namespace App\Services\Loans;

use App\Models\Tenant\FundTier;
use App\Models\Tenant\Loan;
use App\Models\Tenant\LoanTier;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Loan queue display and persisted queue_position follow business rules:
 * emergencies first (FIFO by applied_at), then by fund tier priority, loan tier 1 before higher tiers (FIFO),
 * and within each fund tier bucket a capacity-aware pass (requests that fit the tier's currently
 * disbursable pool first, preserving relative order). Queued = approved or partially disbursed.
 */
class LoanQueueOrderingService
{
    /**
     * @param  Collection<int, Loan>|EloquentCollection<int, Loan>  $loans
     * @return Collection<int, Loan>
     */
    public static function orderIncomingPending(Collection|EloquentCollection $loans): Collection
    {
        $c = $loans instanceof Collection ? $loans : collect($loans->all());
        if ($c->isEmpty()) {
            return $c;
        }

        $fundTiers = FundTier::query()->where('is_active', true)->get()->keyBy('id');

        [$emergency, $rest] = $c->partition(fn (Loan $l) => (bool) $l->is_emergency);
        $out = $emergency->sort(fn (Loan $a, Loan $b) => $a->applied_at <=> $b->applied_at)->values();

        $byFund = $rest->groupBy(fn (Loan $l) => self::expectedFundTierId($l) ?? 'none');

        $sortedKeys = $byFund->keys()->sort(function ($ka, $kb) use ($fundTiers) {
            if ($ka === $kb) {
                return 0;
            }
            if ($ka === 'none') {
                return 1;
            }
            if ($kb === 'none') {
                return -1;
            }
            $ta = $fundTiers->get((int) $ka);
            $tb = $fundTiers->get((int) $kb);
            if ($ta === null) {
                return 1;
            }
            if ($tb === null) {
                return -1;
            }

            return $ta->tier_number <=> $tb->tier_number;
        })->values();

        foreach ($sortedKeys as $key) {
            $group = $byFund->get($key) ?? collect();
            if ($group->isEmpty()) {
                continue;
            }
            $sorted = $group->sort(function (Loan $a, Loan $b) {
                $da = self::effectiveLoanTierNumber($a);
                $db = self::effectiveLoanTierNumber($b);
                if ($da !== $db) {
                    return $da <=> $db;
                }

                return $a->applied_at <=> $b->applied_at;
            })->values();

            $avail = $key === 'none'
                ? PHP_FLOAT_MAX
                : (float) ($fundTiers->get((int) $key)?->disbursable_pool ?? 0);

            $out = $out->concat(self::applyCapacityBucket(
                $sorted,
                $avail,
                fn (Loan $l) => (float) $l->amount_requested,
            ));
        }

        return $out;
    }

    /**
     * @param  Collection<int, Loan>|EloquentCollection<int, Loan>  $loans
     * @return Collection<int, Loan>
     */
    public static function orderTierQueue(Collection|EloquentCollection $loans, FundTier $fundTier): Collection
    {
        $c = $loans instanceof Collection ? $loans : collect($loans->all());
        if ($c->isEmpty()) {
            return $c;
        }

        $available = (float) $fundTier->disbursable_pool;

        [$emergency, $rest] = $c->partition(fn (Loan $l) => (bool) $l->is_emergency);
        $out = $emergency->sort(fn (Loan $a, Loan $b) => $a->applied_at <=> $b->applied_at)->values();

        [$approved, $active] = $rest->partition(
            fn (Loan $l) => in_array($l->status, ['approved', 'partially_disbursed'], true),
        );

        $approvedSorted = $approved->sort(function (Loan $a, Loan $b) {
            $da = self::effectiveLoanTierNumber($a);
            $db = self::effectiveLoanTierNumber($b);
            if ($da !== $db) {
                return $da <=> $db;
            }

            return $a->applied_at <=> $b->applied_at;
        })->values();

        $out = $out->concat(self::applyCapacityBucket(
            $approvedSorted,
            $available,
            fn (Loan $l) => (float) $l->remainingToDisburse(),
        ));

        return $out->concat($active->sort(fn (Loan $a, Loan $b) => $a->applied_at <=> $b->applied_at)->values());
    }

    public static function resequenceFundTier(?int $fundTierId): void
    {
        if ($fundTierId === null) {
            return;
        }

        $fundTier = FundTier::query()->find($fundTierId);
        if ($fundTier === null) {
            return;
        }

        $loans = Loan::query()
            ->where('fund_tier_id', $fundTierId)
            ->whereIn('status', ['approved', 'partially_disbursed', 'active'])
            ->with(['loanTier'])
            ->orderBy('id')
            ->get();

        $ordered = self::orderTierQueue($loans, $fundTier);

        DB::transaction(function () use ($ordered): void {
            foreach ($ordered->values() as $idx => $loan) {
                $pos = $idx + 1;
                if ((int) $loan->queue_position !== $pos) {
                    Loan::query()->whereKey($loan->id)->update(['queue_position' => $pos]);
                }
            }
        });
    }

    /**
     * Move non-emergency queued/active loans onto the fund pool currently linked to their
     * loan amount band, then resequence every pool that gained or lost loans.
     *
     * Pending intake already resolves the pool from the live mapping; approved/process/tiers
     * queues use stamped {@see Loan::$fund_tier_id}, so remapping must update those rows.
     *
     * @param  list<int>  $loanTierIds  Empty list = all loan tiers that currently have a fund pool.
     * @return list<int> Fund tier ids that were resequenced
     */
    public static function realignLoansToCurrentFundMapping(array $loanTierIds = []): array
    {
        $loanTierIds = collect($loanTierIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        $mapQuery = LoanTier::query()->whereNotNull('fund_tier_id');
        if ($loanTierIds !== []) {
            $mapQuery->whereIn('id', $loanTierIds);
        }

        /** @var Collection<int, int> $loanTierToFundTier */
        $loanTierToFundTier = $mapQuery->pluck('fund_tier_id', 'id')
            ->map(fn ($fundTierId): int => (int) $fundTierId);

        $loansQuery = Loan::query()
            ->whereIn('status', ['approved', 'partially_disbursed', 'active'])
            ->where('is_emergency', false)
            ->whereNotNull('loan_tier_id');

        if ($loanTierIds !== []) {
            $loansQuery->whereIn('loan_tier_id', $loanTierIds);
        }

        $loans = $loansQuery->get(['id', 'loan_tier_id', 'fund_tier_id']);
        $affectedFundTier = collect();

        DB::transaction(function () use ($loans, $loanTierToFundTier, $affectedFundTier): void {
            foreach ($loans as $loan) {
                $loanTierId = (int) $loan->loan_tier_id;
                if (! $loanTierToFundTier->has($loanTierId)) {
                    continue;
                }

                $newFundTierId = (int) $loanTierToFundTier->get($loanTierId);
                $oldFundTierId = $loan->fund_tier_id !== null ? (int) $loan->fund_tier_id : null;

                if ($oldFundTierId === $newFundTierId) {
                    continue;
                }

                if ($oldFundTierId !== null) {
                    $affectedFundTier->push($oldFundTierId);
                }
                $affectedFundTier->push($newFundTierId);

                Loan::query()->whereKey($loan->id)->update([
                    'fund_tier_id' => $newFundTierId,
                    'queue_position' => null,
                ]);
            }
        });

        $fundTierIds = $affectedFundTier->unique()->filter()->values()->all();

        foreach ($fundTierIds as $fundTierId) {
            self::resequenceFundTier((int) $fundTierId);
        }

        return array_map('intval', $fundTierIds);
    }

    /**
     * Move queued/active loans off a fund pool that is being removed onto the pool resolved
     * from their loan amount band (or the emergency pool). Used before soft-deleting a fund tier.
     *
     * @return list<int> Destination fund tier ids that were resequenced
     */
    public static function reassignLoansFromFundTier(int $fundTierId): array
    {
        $loans = Loan::query()
            ->where('fund_tier_id', $fundTierId)
            ->whereIn('status', ['approved', 'partially_disbursed', 'active'])
            ->get();

        if ($loans->isEmpty()) {
            return [];
        }

        $affectedFundTier = collect();

        DB::transaction(function () use ($loans, $fundTierId, $affectedFundTier): void {
            foreach ($loans as $loan) {
                $target = FundTier::resolveForLoan($loan);
                $newFundTierId = $target !== null ? (int) $target->id : null;

                if ($newFundTierId === null || $newFundTierId === $fundTierId) {
                    continue;
                }

                Loan::query()->whereKey($loan->id)->update([
                    'fund_tier_id' => $newFundTierId,
                    'queue_position' => null,
                ]);

                $affectedFundTier->push($newFundTierId);
            }
        });

        $fundTierIds = $affectedFundTier->unique()->filter()->values()->all();

        foreach ($fundTierIds as $id) {
            self::resequenceFundTier((int) $id);
        }

        return array_map('intval', $fundTierIds);
    }

    /**
     * @param  Collection<int, Loan>  $sortedLoans
     * @return Collection<int, Loan>
     */
    protected static function applyCapacityBucket(
        Collection $sortedLoans,
        float $available,
        callable $amountNeeded,
    ): Collection {
        $remaining = max(0.0, $available);
        $fits = [];
        $later = [];
        foreach ($sortedLoans as $loan) {
            $amt = (float) $amountNeeded($loan);
            if ($remaining + 1e-6 >= $amt) {
                $fits[] = $loan;
                $remaining -= $amt;
            } else {
                $later[] = $loan;
            }
        }

        return collect([...$fits, ...$later]);
    }

    protected static function expectedFundTierId(Loan $loan): ?int
    {
        if ($loan->is_emergency) {
            return FundTier::emergency()?->id;
        }

        return self::directFundTierForLoan($loan)?->id;
    }

    protected static function directFundTierForLoan(Loan $loan): ?FundTier
    {
        $lt = self::effectiveLoanTier($loan);
        if ($lt === null) {
            return null;
        }

        return FundTier::forLoanTier((int) $lt->id);
    }

    protected static function effectiveLoanTier(Loan $loan): ?LoanTier
    {
        if ($loan->relationLoaded('loanTier') && $loan->loanTier !== null) {
            return $loan->loanTier;
        }
        if ($loan->loan_tier_id !== null) {
            return $loan->loanTier()->first();
        }

        return LoanTier::forAmount((float) $loan->amount_requested);
    }

    protected static function effectiveLoanTierNumber(Loan $loan): int
    {
        $lt = self::effectiveLoanTier($loan);

        return $lt !== null ? (int) $lt->tier_number : 999;
    }
}
