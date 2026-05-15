<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphMany;

class Contribution extends Model
{
    use HasFactory;

    protected $fillable = [
        'member_id',
        'period',
        'amount',
        'status',
        'posted_at',
    ];

    protected function casts(): array
    {
        return [
            'period' => 'date',
            'amount' => 'decimal:2',
            'posted_at' => 'datetime',
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

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopePosted($query)
    {
        return $query->where('status', 'posted');
    }
}
