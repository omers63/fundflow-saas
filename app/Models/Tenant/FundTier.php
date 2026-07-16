<?php

namespace App\Models\Tenant;

use App\Services\Loans\LoanQueueOrderingService;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;

class FundTier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tier_number',
        'label',
        'percentage',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'percentage' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::deleting(function (FundTier $tier): bool {
            if ($tier->isEmergency()) {
                return false;
            }

            // Reassign stamped loans while loan-tier → fund links still resolve to another pool.
            LoanQueueOrderingService::reassignLoansFromFundTier((int) $tier->id);

            LoanTier::query()
                ->where('fund_tier_id', $tier->id)
                ->update(['fund_tier_id' => null]);

            return true;
        });
    }

    public function loanTiers(): HasMany
    {
        return $this->hasMany(LoanTier::class);
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function getLabelAttribute($value): string
    {
        return $value ?? ($this->tier_number === 0 ? 'Emergency' : "Tier {$this->tier_number}");
    }

    public function isEmergency(): bool
    {
        return (int) $this->tier_number === 0;
    }

    /** Next non-emergency tier number for a newly created fund tier. */
    public static function nextTierNumber(): int
    {
        $max = (int) static::query()->where('tier_number', '>', 0)->max('tier_number');

        return max(1, $max + 1);
    }

    /**
     * Attach the given loan tiers to this fund tier and detach any previously linked ones
     * that are no longer selected. Loan tiers already owned by another fund tier are skipped.
     *
     * @param  list<int|string>  $loanTierIds
     */
    public function syncLoanTiers(array $loanTierIds): void
    {
        if ($this->isEmergency()) {
            $previouslyLinked = LoanTier::query()
                ->where('fund_tier_id', $this->id)
                ->pluck('id')
                ->all();

            LoanTier::query()
                ->where('fund_tier_id', $this->id)
                ->update(['fund_tier_id' => null]);

            LoanQueueOrderingService::realignLoansToCurrentFundMapping(
                array_map('intval', $previouslyLinked),
            );

            return;
        }

        $ids = collect($loanTierIds)
            ->map(fn (int|string $id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->unique()
            ->values()
            ->all();

        DB::transaction(function () use ($ids): void {
            $previouslyLinked = LoanTier::query()
                ->where('fund_tier_id', $this->id)
                ->pluck('id')
                ->all();

            LoanTier::query()
                ->where('fund_tier_id', $this->id)
                ->when($ids !== [], fn ($query) => $query->whereNotIn('id', $ids))
                ->update(['fund_tier_id' => null]);

            if ($ids !== []) {
                LoanTier::query()
                    ->whereIn('id', $ids)
                    ->where(function ($query): void {
                        $query->whereNull('fund_tier_id')
                            ->orWhere('fund_tier_id', $this->id);
                    })
                    ->update(['fund_tier_id' => $this->id]);
            }

            $affectedLoanTier = array_values(array_unique([
                ...array_map('intval', $previouslyLinked),
                ...$ids,
            ]));

            $resequencedFundTier = LoanQueueOrderingService::realignLoansToCurrentFundMapping($affectedLoanTier);

            $affectedFundTier = collect([$this->id])
                ->merge($resequencedFundTier)
                ->merge(
                    LoanTier::query()
                        ->whereIn('id', $affectedLoanTier)
                        ->whereNotNull('fund_tier_id')
                        ->pluck('fund_tier_id'),
                )
                ->map(fn ($id): int => (int) $id)
                ->unique()
                ->filter()
                ->all();

            foreach ($affectedFundTier as $fundTierId) {
                if (in_array($fundTierId, $resequencedFundTier, true)) {
                    continue;
                }

                LoanQueueOrderingService::resequenceFundTier($fundTierId);
            }
        });
    }

    /** Return the active emergency fund tier. */
    public static function emergency(): ?self
    {
        return static::where('tier_number', 0)->where('is_active', true)->first();
    }

    /**
     * Return the active fund tier linked to the given loan tier.
     * Returns null when the loan tier is unassigned or its fund pool is inactive.
     */
    public static function forLoanTier(int $loanTierId): ?self
    {
        $loanTier = LoanTier::query()->find($loanTierId);

        if ($loanTier?->fund_tier_id === null) {
            return null;
        }

        return static::query()
            ->whereKey($loanTier->fund_tier_id)
            ->where('is_active', true)
            ->first();
    }

    /**
     * Resolve the correct fund tier for a loan:
     *  - Emergency flag  → emergency fund tier
     *  - Otherwise       → fund tier linked to the loan's loan tier
     */
    public static function resolveForLoan(Loan $loan): ?self
    {
        if ($loan->is_emergency) {
            return static::emergency();
        }

        if ($loan->loan_tier_id) {
            return static::forLoanTier((int) $loan->loan_tier_id);
        }

        $amount = (float) ($loan->amount_approved ?? $loan->amount_requested ?? 0);
        if ($amount <= 0) {
            return null;
        }

        $loanTier = LoanTier::forAmount($amount);

        return $loanTier !== null ? static::forLoanTier($loanTier->id) : null;
    }

    /**
     * Allocated amount = master fund balance × (percentage / 100).
     * Available = allocated - active exposure.
     */
    public function getAllocatedAmountAttribute(): float
    {
        $masterBalance = (float) (Account::masterFund()?->balance ?? 0);

        return $masterBalance * ($this->percentage / 100);
    }

    public function getActiveExposureAttribute(): float
    {
        return $this->activeLoanExposure() + $this->queuedRemainingExposure();
    }

    /** Fully disbursed (active) loans still committed against this pool. */
    public function activeLoanExposure(): float
    {
        return (float) Loan::where('fund_tier_id', $this->id)
            ->where('status', 'active')
            ->sum('amount_approved');
    }

    /** Remaining (approved − disbursed) of loans queued for disbursement in this pool. */
    public function queuedRemainingExposure(): float
    {
        return max(0.0, (float) Loan::where('fund_tier_id', $this->id)
            ->whereIn('status', ['approved', 'partially_disbursed'])
            ->selectRaw('COALESCE(SUM(COALESCE(amount_approved, 0) - COALESCE(amount_disbursed, 0)), 0) as total')
            ->value('total'));
    }

    public function getAvailableAmountAttribute(): float
    {
        return max(0, $this->allocated_amount - $this->active_exposure);
    }

    /**
     * Per-tier lending headroom (policy cap for this band only — not additive across tiers).
     * Overlapping tier percentages share one master fund; use {@see LoanQueueService::masterFundDisbursableNow()}
     * for the fund-wide ceiling.
     */
    public function getDisbursablePoolAttribute(): float
    {
        $masterBalance = (float) (Account::masterFund()?->balance ?? 0);

        return max(0.0, min($this->allocated_amount - $this->activeLoanExposure(), $masterBalance));
    }

    public function getActiveLoansCountAttribute(): int
    {
        return Loan::where('fund_tier_id', $this->id)
            ->whereIn('status', ['approved', 'partially_disbursed', 'active'])
            ->count();
    }

    /** Next queue position for a new loan in this fund tier. */
    public function nextQueuePosition(): int
    {
        return (Loan::where('fund_tier_id', $this->id)
            ->whereIn('status', ['pending', 'approved'])
            ->max('queue_position') ?? 0) + 1;
    }
}
