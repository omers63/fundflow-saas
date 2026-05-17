<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FundTier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tier_number',
        'label',
        'loan_tier_id',
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

    public function loanTier(): BelongsTo
    {
        return $this->belongsTo(LoanTier::class);
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
        return $this->tier_number === 0;
    }

    /** Return the active emergency fund tier. */
    public static function emergency(): ?self
    {
        return static::where('tier_number', 0)->where('is_active', true)->first();
    }

    /**
     * Return the active fund tier linked to the given loan tier.
     * Falls back to emergency if no specific fund tier is configured.
     */
    public static function forLoanTier(int $loanTierId): ?self
    {
        return static::where('loan_tier_id', $loanTierId)->where('is_active', true)->first()
            ?? static::emergency();
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
            return static::forLoanTier($loan->loan_tier_id);
        }

        return null;
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
        return (float) Loan::where('fund_tier_id', $this->id)
            ->whereIn('status', ['approved', 'active'])
            ->sum('amount_approved');
    }

    public function getAvailableAmountAttribute(): float
    {
        return max(0, $this->allocated_amount - $this->active_exposure);
    }

    public function getActiveLoansCountAttribute(): int
    {
        return Loan::where('fund_tier_id', $this->id)
            ->whereIn('status', ['approved', 'active'])
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
