<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Loan extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_id',
        'amount',
        'interest_rate',
        'term_months',
        'monthly_repayment',
        'total_repaid',
        'status',
        'applied_at',
        'approved_at',
        'disbursed_at',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'interest_rate' => 'decimal:2',
            'monthly_repayment' => 'decimal:2',
            'total_repaid' => 'decimal:2',
            'applied_at' => 'datetime',
            'approved_at' => 'datetime',
            'disbursed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(LoanRepayment::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'reference');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeActive($query)
    {
        return $query->whereIn('status', ['disbursed', 'repaying']);
    }

    public function getOutstandingBalance(): float
    {
        $totalDue = $this->amount + ($this->amount * $this->interest_rate / 100);

        return max(0, $totalDue - (float) $this->total_repaid);
    }

    public function isFullyRepaid(): bool
    {
        return $this->getOutstandingBalance() <= 0;
    }
}
