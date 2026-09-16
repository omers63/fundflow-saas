<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberPrivacyRequest extends Model
{
    public const TYPE_DATA_EXPORT = 'data_export';

    public const TYPE_ACCOUNT_CLOSURE = 'account_closure';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const STATUS_COMPLETED = 'completed';

    protected $fillable = [
        'member_id',
        'type',
        'status',
        'reason',
        'export_meta',
        'export_path',
        'reviewed_by',
        'reviewed_at',
        'admin_notes',
        'completed_at',
    ];

    protected function casts(): array
    {
        return [
            'export_meta' => 'array',
            'reviewed_at' => 'datetime',
            'completed_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public static function typeLabels(): array
    {
        return [
            self::TYPE_DATA_EXPORT => __('Download my data'),
            self::TYPE_ACCOUNT_CLOSURE => __('Account closure / anonymization'),
        ];
    }
}
