<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'account_id',
        'member_id',
        'type',
        'amount',
        'balance_after',
        'reference_type',
        'reference_id',
        'description',
        'transacted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
            'transacted_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(Account::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function reference(): MorphTo
    {
        return $this->morphTo();
    }

    public function sourcedBankTransaction(): ?BankTransaction
    {
        if ($this->reference instanceof BankTransaction) {
            return $this->reference;
        }

        return BankTransaction::query()
            ->where('master_cash_transaction_id', $this->id)
            ->first();
    }

    public function bankImportSummary(): ?string
    {
        $bankTransaction = $this->sourcedBankTransaction();

        if ($bankTransaction === null) {
            return null;
        }

        $date = $bankTransaction->transaction_date?->format('M j, Y');

        return __('Bank import #:id (:date) — :description', [
            'id' => $bankTransaction->id,
            'date' => $date,
            'description' => $bankTransaction->description,
        ]);
    }

    public function scopeCredits($query)
    {
        return $query->where('type', 'credit');
    }

    public function scopeDebits($query)
    {
        return $query->where('type', 'debit');
    }

    public function isDebit(): bool
    {
        return $this->type === 'debit';
    }

    /**
     * Signed amount for display: debits are negative, credits positive (matches bank import style).
     */
    public function getSignedAmount(): float
    {
        $amount = abs((float) $this->amount);

        return $this->isDebit() ? -$amount : $amount;
    }

    public function referenceSummary(): ?string
    {
        if (blank($this->reference_type)) {
            return null;
        }

        $reference = $this->reference;

        if ($reference === null) {
            return class_basename($this->reference_type).' #'.$this->reference_id;
        }

        return match (true) {
            $reference instanceof BankTransaction => $reference->description,
            $reference instanceof FundPosting => filled($reference->reference)
            ? $reference->reference
            : __('Deposit #:id', ['id' => $reference->id]),
            $reference instanceof Contribution => __('Contribution #:id', ['id' => $reference->id]),
            $reference instanceof Loan => __('Loan #:id', ['id' => $reference->id]),
            $reference instanceof LoanRepayment => __('Loan repayment #:id', ['id' => $reference->id]),
            default => class_basename($reference).': #'.$reference->getKey(),
        };
    }
}
