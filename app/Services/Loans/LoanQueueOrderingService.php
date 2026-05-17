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
 * and within each fund tier bucket a capacity-aware pass (requests that fit available slack first, preserving relative order).
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
                : (float) ($fundTiers->get((int) $key)?->available_amount ?? 0);

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

        $available = (float) $fundTier->available_amount;

        [$emergency, $rest] = $c->partition(fn (Loan $l) => (bool) $l->is_emergency);
        $out = $emergency->sort(fn (Loan $a, Loan $b) => $a->applied_at <=> $b->applied_at)->values();

        [$approved, $active] = $rest->partition(fn (Loan $l) => $l->status === 'approved');

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
            ->whereIn('status', ['approved', 'active'])
            ->with(['loanTier'])
            ->orderBy('id')
            ->get();

        $ordered = self::orderTierQueue($loans, $fundTier);

        DB::transaction(function () use ($ordered) {
            foreach ($ordered->values() as $idx => $loan) {
                $pos = $idx + 1;
                if ((int) $loan->queue_position !== $pos) {
                    Loan::query()->whereKey($loan->id)->update(['queue_position' => $pos]);
                }
            }
        });
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

        return FundTier::query()->where('loan_tier_id', $lt->id)->where('is_active', true)->first();
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
