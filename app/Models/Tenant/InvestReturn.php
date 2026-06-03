<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InvestReturn extends Model
{
    protected $fillable = [
        'amount',
        'description',
        'transacted_at',
        'bank_transaction_id',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transacted_at' => 'datetime',
        ];
    }

    public function bankTransaction(): BelongsTo
    {
        return $this->belongsTo(BankTransaction::class);
    }
}
