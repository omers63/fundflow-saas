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

    /** @var array<string, float> */
    private static array $repaymentSumCache = [];

    /** @var array<string, int> */
    private static array $paidInstallmentCountCache = [];

    protected $fillable = [
        'loan_id',
        'installment_number',
        'amount',
        'due_date',
        'paid_at',
        'waived_at',
        'status',
        'is_late',
        'late_fee_amount',
        'paid_by_guarantor',
        'show_as_loan_repayment_in_collections',
        'collection_status',
        'amount_collected',
        'overdue_since',
        'late_fee_tier',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'due_date' => 'date',
            'paid_at' => 'datetime',
            'waived_at' => 'datetime',
            'is_late' => 'boolean',
            'late_fee_amount' => 'decimal:2',
            'amount_collected' => 'decimal:2',
            'overdue_since' => 'datetime',
            'late_fee_tier' => 'integer',
            'paid_by_guarantor' => 'boolean',
            'show_as_loan_repayment_in_collections' => 'boolean',
        ];
    }

    public function loan(): BelongsTo
    {
        return $this->belongsTo(Loan::class);
    }

    /**
     * Cash collected for this installment in the payment event (collection UI / KPIs).
     *
     * Uses {@see amount_collected} when set. For legacy imports where the final schedule slot
     * exceeds the remaining principal, falls back to the repayment row on {@see paid_at}.
     */
    public function collectedCashAmount(): float
    {
        if ((float) ($this->amount_collected ?? 0) > 0) {
            return (float) $this->amount_collected;
        }

        if ($this->paid_at === null || ! $this->isPaid()) {
            return (float) $this->amount;
        }

        $repaymentTotal = $this->repaymentSumOnPaidDate();

        if ($repaymentTotal <= 0) {
            return (float) $this->amount;
        }

        if ($this->paidInstallmentCountOnPaidDate() === 1) {
            return $repaymentTotal;
        }

        return (float) $this->amount;
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

    public function isWaived(): bool
    {
        return $this->status === 'waived';
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

    public function scopeOrderByLoanOutstanding(Builder $query, string $direction = 'desc'): Builder
    {
        $direction = strtolower($direction) === 'asc' ? 'asc' : 'desc';

        return $query->orderBy(
            static::query()
                ->from('loan_installments as outstanding_installments')
                ->selectRaw('coalesce(sum(outstanding_installments.amount), 0)')
                ->whereColumn('outstanding_installments.loan_id', 'loan_installments.loan_id')
                ->whereIn('outstanding_installments.status', ['pending', 'overdue']),
            $direction,
        );
    }

    private function repaymentSumOnPaidDate(): float
    {
        $key = $this->loan_id.'|'.$this->paid_at->toDateString();

        if (! array_key_exists($key, self::$repaymentSumCache)) {
            self::$repaymentSumCache[$key] = (float) LoanRepayment::query()
                ->where('loan_id', $this->loan_id)
                ->whereDate('paid_at', $this->paid_at)
                ->sum('amount');
        }

        return self::$repaymentSumCache[$key];
    }

    private function paidInstallmentCountOnPaidDate(): int
    {
        $key = $this->loan_id.'|'.$this->paid_at->toDateString();

        if (! array_key_exists($key, self::$paidInstallmentCountCache)) {
            self::$paidInstallmentCountCache[$key] = (int) static::query()
                ->where('loan_id', $this->loan_id)
                ->where('status', 'paid')
                ->whereDate('paid_at', $this->paid_at)
                ->count();
        }

        return self::$paidInstallmentCountCache[$key];
    }
}
