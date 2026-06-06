<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReconciliationSnapshot extends Model
{
    public const MODE_REALTIME = 'realtime';

    public const MODE_DAILY = 'daily';

    public const MODE_MONTHLY = 'monthly';

    protected $fillable = [
        'mode',
        'as_of',
        'period_start',
        'period_end',
        'is_passing',
        'critical_issues',
        'warnings',
        'summary',
        'report',
        'created_by_id',
    ];

    protected function casts(): array
    {
        return [
            'as_of' => 'datetime',
            'period_start' => 'datetime',
            'period_end' => 'datetime',
            'is_passing' => 'boolean',
            'summary' => 'array',
            'report' => 'array',
        ];
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_id');
    }
}
