<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class BankTransaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'bank_statement_id',
        'transaction_date',
        'description',
        'amount',
        'reference',
        'transaction_type',
        'status',
        'member_id',
        'hash',
        'raw_data',
        'is_cleared',
        'cleared_at',
        'fund_posting_id',
        'membership_application_id',
        'duplicate_of_id',
        'master_cash_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'transaction_date' => 'date',
            'amount' => 'decimal:2',
            'is_cleared' => 'boolean',
            'cleared_at' => 'datetime',
        ];
    }

    public function bankStatement(): BelongsTo
    {
        return $this->belongsTo(BankStatement::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function fundPosting(): BelongsTo
    {
        return $this->belongsTo(FundPosting::class);
    }

    public function membershipApplication(): BelongsTo
    {
        return $this->belongsTo(MembershipApplication::class);
    }

    public function duplicateOf(): BelongsTo
    {
        return $this->belongsTo(self::class, 'duplicate_of_id');
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'reference');
    }

    public function masterCashTransaction(): BelongsTo
    {
        return $this->belongsTo(Transaction::class, 'master_cash_transaction_id');
    }

    public function resolveMasterCashTransaction(bool $persistLink = true): ?Transaction
    {
        if ($this->master_cash_transaction_id) {
            $ledger = $this->masterCashTransaction;

            if ($ledger !== null) {
                return $ledger;
            }
        }

        $masterCashAccountId = Account::masterCash()?->id;

        if ($masterCashAccountId === null) {
            return null;
        }

        $ledger = $this->transactions()
            ->where('account_id', $masterCashAccountId)
            ->orderBy('id')
            ->first();

        if ($ledger === null) {
            return null;
        }

        if ($persistLink && $this->master_cash_transaction_id !== $ledger->id) {
            $this->forceFill(['master_cash_transaction_id' => $ledger->id])->saveQuietly();
        }

        return $ledger;
    }

    public function masterCashMirrorSummary(): ?string
    {
        if (! in_array($this->status, ['mirrored', 'posted'], true)) {
            return null;
        }

        $ledger = $this->resolveMasterCashTransaction();

        if ($ledger === null) {
            return __('Mirrored (ledger link could not be resolved)');
        }

        $date = $ledger->transacted_at?->format('M j, Y');

        return __('Master cash ledger #:id (:date)', [
            'id' => $ledger->id,
            'date' => $date,
        ]);
    }

    public function isCredit(): bool
    {
        return (float) $this->amount > 0;
    }

    public function isDebit(): bool
    {
        return (float) $this->amount < 0;
    }

    public function scopeImported($query)
    {
        return $query->where('status', 'imported');
    }

    public function scopeMirrored($query)
    {
        return $query->where('status', 'mirrored');
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }

    public function scopeCredits($query)
    {
        return $query->where('amount', '>', 0);
    }

    public function scopeDebits($query)
    {
        return $query->where('amount', '<', 0);
    }

    public function scopeUncleared($query)
    {
        return $query->where('is_cleared', false);
    }

    public function scopeCleared($query)
    {
        return $query->where('is_cleared', true);
    }

    public function scopeDuplicate($query)
    {
        return $query->where('status', 'duplicate');
    }
}
