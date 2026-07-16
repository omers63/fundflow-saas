<?php

namespace App\Models\Tenant;

use App\Filament\Support\MoneyDisplay;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class LoanTier extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'tier_number',
        'label',
        'min_amount',
        'max_amount',
        'min_monthly_installment',
        'is_active',
        'fund_tier_id',
    ];

    protected function casts(): array
    {
        return [
            'min_amount' => 'decimal:2',
            'max_amount' => 'decimal:2',
            'min_monthly_installment' => 'decimal:2',
            'is_active' => 'boolean',
        ];
    }

    public function loans(): HasMany
    {
        return $this->hasMany(Loan::class);
    }

    public function fundTier(): BelongsTo
    {
        return $this->belongsTo(FundTier::class);
    }

    /** Next tier number for a newly created loan amount band. */
    public static function nextTierNumber(): int
    {
        $max = (int) static::query()->max('tier_number');

        return max(0, $max + 1);
    }

    /** Find the tier that covers the given loan amount. Returns null if out of range. */
    public static function forAmount(float $amount): ?self
    {
        return static::where('is_active', true)
            ->where('min_amount', '<=', $amount)
            ->where('max_amount', '>=', $amount)
            ->first();
    }

    public function getLabelAttribute($value): string
    {
        return $value ?? "Tier {$this->tier_number}";
    }

    public function getRangeAttribute(): string
    {
        $currency = Setting::get('general', 'currency', 'USD');

        return (MoneyDisplay::format((float) $this->min_amount, $currency, precision: 0) ?? '')
            .' – '
            .(MoneyDisplay::format((float) $this->max_amount, $currency, precision: 0) ?? '');
    }

    /** Total outstanding active loan amount in this tier. */
    public function getActiveExposureAttribute(): float
    {
        return (float) Loan::where('loan_tier_id', $this->id)
            ->whereIn('status', ['approved', 'active'])
            ->sum('amount_approved');
    }

    /** Count of active loans in this tier. */
    public function getActiveLoansCountAttribute(): int
    {
        return Loan::where('loan_tier_id', $this->id)
            ->whereIn('status', ['approved', 'active'])
            ->count();
    }
}
