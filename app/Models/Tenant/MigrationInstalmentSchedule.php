<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationInstalmentSchedule extends Model
{
    protected $fillable = [
        'member_id',
        'cycle_date',
        'amount',
        'status',
        'collected_at',
    ];

    protected function casts(): array
    {
        return [
            'cycle_date' => 'date',
            'amount' => 'decimal:2',
            'collected_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }
}
