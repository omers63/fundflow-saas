<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DisbursementBatchItem extends Model
{
    public const STATUS_INCLUDED = 'included';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_CLEARED = 'cleared';

    protected $fillable = [
        'disbursement_batch_id',
        'outbound_payment_id',
        'amount',
        'payee_name',
        'payee_iban',
        'status',
        'ack_status',
        'ack_reference',
        'ack_reason',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(DisbursementBatch::class, 'disbursement_batch_id');
    }

    public function outboundPayment(): BelongsTo
    {
        return $this->belongsTo(OutboundPayment::class);
    }
}
