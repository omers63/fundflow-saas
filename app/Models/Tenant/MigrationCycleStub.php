<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MigrationCycleStub extends Model
{
    public const STATUS_UNRESOLVED = 'unresolved';

    public const CLASS_WAIVED = 'waived';

    public const CLASS_BACKDATED_PAID = 'backdated_paid';

    public const CLASS_BACKDATED_DUE = 'backdated_due';

    public const CLASS_OB_ABSORBED = 'ob_absorbed';

    public const CLASS_ESCALATED = 'escalated';

    protected $fillable = [
        'member_id',
        'cycle_date',
        'amount_due',
        'status',
        'classification',
        'resolution_method',
        'late_fee_exempt',
        'notes',
        'classified_at',
        'classified_by',
    ];

    protected function casts(): array
    {
        return [
            'cycle_date' => 'date',
            'amount_due' => 'decimal:2',
            'late_fee_exempt' => 'boolean',
            'classified_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function scopeUnresolved($query)
    {
        return $query->where('status', self::STATUS_UNRESOLVED);
    }
}
