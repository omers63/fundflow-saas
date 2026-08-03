<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class OutboundPayment extends Model
{
    public const TYPE_CASH_OUT = 'cash_out';

    public const TYPE_EXPENSE_OUT = 'expense_out';

    public const TYPE_FEE_OUT = 'fee_out';

    public const TYPE_INVEST_OUT = 'invest_out';

    public const STATUS_PENDING = 'pending';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_CANCELLED = 'cancelled';

    public const METHOD_BANK_TRANSFER = 'bank_transfer';

    public const METHOD_WIRE = 'wire';

    public const METHOD_CHECK = 'check';

    public const METHOD_CASH = 'cash';

    public const METHOD_OTHER = 'other';

    protected $fillable = [
        'type',
        'source_type',
        'source_id',
        'member_id',
        'payee_name',
        'amount',
        'reason',
        'instruction_date',
        'status',
        'bank_transaction_id',
        'payee_iban',
        'payee_bank_account_number',
        'payment_method',
        'check_number',
        'payment_reference',
        'paid_at',
        'notes',
        'completion_notes',
        'created_by',
        'completed_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'instruction_date' => 'date',
            'paid_at' => 'datetime',
        ];
    }

    public function source(): MorphTo
    {
        return $this->morphTo();
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function completer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'completed_by');
    }

    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_PENDING);
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isCompleted(): bool
    {
        return $this->status === self::STATUS_COMPLETED;
    }

    /**
     * @return array<string, string>
     */
    public static function typeLabels(): array
    {
        return [
            self::TYPE_CASH_OUT => __('Member cash-out'),
            self::TYPE_EXPENSE_OUT => __('Expense disbursement'),
            self::TYPE_FEE_OUT => __('Fee disbursement'),
            self::TYPE_INVEST_OUT => __('Invest disbursement'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_PENDING => __('Pending transfer'),
            self::STATUS_COMPLETED => __('Completed'),
            self::STATUS_CANCELLED => __('Cancelled'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function paymentMethodLabels(): array
    {
        return [
            self::METHOD_BANK_TRANSFER => __('Bank transfer'),
            self::METHOD_WIRE => __('Wire'),
            self::METHOD_CHECK => __('Check'),
            self::METHOD_CASH => __('Cash'),
            self::METHOD_OTHER => __('Other'),
        ];
    }

    public function typeLabel(): string
    {
        return self::typeLabels()[$this->type] ?? ucfirst(str_replace('_', ' ', (string) $this->type));
    }

    public function statusLabel(): string
    {
        return self::statusLabels()[$this->status] ?? ucfirst((string) $this->status);
    }

    public function paymentMethodLabel(): ?string
    {
        if ($this->payment_method === null) {
            return null;
        }

        return self::paymentMethodLabels()[$this->payment_method]
            ?? ucfirst(str_replace('_', ' ', $this->payment_method));
    }
}
