<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

class LoanInstallment extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'loan_id',
        'installment_number',
        'amount',
        'due_date',
        'paid_at',
        'status',
        'is_late',
        'late_fee_amount',
        'paid_by_guarantor',
        'show_as_loan_repayment_in_collections',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'is_late' => 'boolean',
            'late_fee_amount' => 'decimal:2',
            'paid_by_guarantor' => 'boolean',
            'show_as_loan_repayment_in_collections' => 'boolean',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /** True when migration {@code show_as_loan_repayment_in_collections} has been applied. */
    public static function hasCollectionsVisibilityColumn(): bool
    {
        return Schema::hasColumn((new static)->getTable(), 'show_as_loan_repayment_in_collections');
    }

    /**
     * Paid installments shown in contribution/collection unions only when the flag is true.
     * If the column is missing (migrate not run), include all paid rows (legacy behaviour).
     */
    public static function scopePaidVisibleInCollections(Builder $query): Builder
    {
        if (static::hasCollectionsVisibilityColumn()) {
            $query->where('loan_installments.show_as_loan_repayment_in_collections', true);
        }

        return $query;
    }

    public function isPaid(): bool
    {
        return $this->status === 'paid';
    }

    public function isOverdue(): bool
    {
        return $this->status === 'overdue';
    }

    public function scopeOverdue($query)
    {
        return $query->where('status', 'overdue');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
