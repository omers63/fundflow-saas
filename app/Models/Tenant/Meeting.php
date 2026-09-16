<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Meeting extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_OPEN = 'open';

    public const STATUS_CLOSED = 'closed';

    public const STATUS_MINUTES_PUBLISHED = 'minutes_published';

    protected $fillable = [
        'title',
        'description',
        'scheduled_at',
        'status',
        'opened_at',
        'closed_at',
        'minutes_published_at',
        'minutes',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'scheduled_at' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'minutes_published_at' => 'datetime',
        ];
    }

    public function motions(): HasMany
    {
        return $this->hasMany(Motion::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => __('Draft'),
            self::STATUS_OPEN => __('Open'),
            self::STATUS_CLOSED => __('Closed'),
            self::STATUS_MINUTES_PUBLISHED => __('Minutes published'),
        ];
    }
}
