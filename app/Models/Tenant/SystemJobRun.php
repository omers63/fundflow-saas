<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SystemJobRun extends Model
{
    public const STATUS_RUNNING = 'running';

    public const STATUS_SUCCESS = 'success';

    public const STATUS_FAILED = 'failed';

    public const TRIGGER_SCHEDULE = 'schedule';

    public const TRIGGER_MANUAL = 'manual';

    protected $fillable = [
        'job_key',
        'command',
        'trigger',
        'status',
        'exit_code',
        'started_at',
        'finished_at',
        'duration_ms',
        'triggered_by',
        'summary',
        'output',
    ];

    protected function casts(): array
    {
        return [
            'started_at' => 'datetime',
            'finished_at' => 'datetime',
            'summary' => 'array',
        ];
    }

    public function triggeredByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'triggered_by');
    }

    public function scopeForJob($query, string $jobKey)
    {
        return $query->where('job_key', $jobKey);
    }

    public function scopeLatestFirst($query)
    {
        return $query->orderByDesc('started_at');
    }
}
