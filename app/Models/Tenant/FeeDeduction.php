<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class FeeDeduction extends Model
{
    protected $fillable = [
        'member_id',
        'amount',
        'description',
        'transacted_at',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'transacted_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function transactions(): MorphMany
    {
        return $this->morphMany(Transaction::class, 'reference');
    }
}
