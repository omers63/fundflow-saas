<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationException extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_ESCALATED = 'escalated';

    protected $fillable = [
        'exception_code',
        'exception_type',
        'domain',
        'severity',
        'amount_delta',
        'affected_entities',
        'auto_resolve_attempted',
        'auto_resolve_reason',
        'status',
        'assigned_to',
        'resolution_notes',
        'resolution_action',
        'resolved_at',
        'sla_deadline',
        'deferred_until',
        'raised_at',
    ];

    protected function casts(): array
    {
        return [
            'amount_delta' => 'decimal:2',
            'affected_entities' => 'array',
            'auto_resolve_attempted' => 'boolean',
            'resolved_at' => 'datetime',
            'sla_deadline' => 'datetime',
            'deferred_until' => 'datetime',
            'raised_at' => 'datetime',
        ];
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', self::STATUS_OPEN);
    }
}
