<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberSavingsGoal extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_ACHIEVED = 'achieved';

    public const STATUS_CANCELLED = 'cancelled';

    protected $fillable = [
        'member_id',
        'title',
        'target_amount',
        'target_date',
        'status',
        'notes',
        'achieved_at',
    ];

    protected function casts(): array
    {
        return [
            'target_amount' => 'decimal:2',
            'target_date' => 'date',
            'achieved_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
