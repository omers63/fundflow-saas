<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class GatewayPayment extends Model
{
    public const PURPOSE_CONTRIBUTION = 'contribution';

    public const PURPOSE_EMI = 'emi';

    public const PURPOSE_FEE = 'fee';

    public const PURPOSE_DEPOSIT = 'deposit';

    public const PURPOSE_ARREARS = 'arrears';

    public const STATUS_PENDING = 'pending';

    public const STATUS_PAID = 'paid';

    public const STATUS_FAILED = 'failed';

    public const STATUS_CANCELLED = 'cancelled';

    public const PROVIDER_FAKE = 'fake';

    public const PROVIDER_MOYASAR = 'moyasar';

    public const PROVIDER_HYPERPAY = 'hyperpay';

    public const PROVIDER_SADAD = 'sadad';

    protected $fillable = [
        'member_id',
        'purpose',
        'amount',
        'currency',
        'status',
        'provider',
        'provider_ref',
        'checkout_url',
        'client_secret',
        'payable_type',
        'payable_id',
        'posted_at',
        'bank_transaction_id',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
            'metadata' => 'array',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }

    public function payable(): MorphTo
    {
        return $this->morphTo();
    }

    public function isPending(): bool
    {
        return $this->status === self::STATUS_PENDING;
    }

    public function isPaid(): bool
    {
        return $this->status === self::STATUS_PAID;
    }
}
