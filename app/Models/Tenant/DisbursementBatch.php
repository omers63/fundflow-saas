<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class DisbursementBatch extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_PENDING_APPROVAL = 'pending_approval';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_GENERATED = 'generated';

    public const STATUS_ACKED = 'acked';

    public const STATUS_CLEARED = 'cleared';

    public const STATUS_CANCELLED = 'cancelled';

    public const FORMAT_AL_RAJHI_CSV = 'al_rajhi_csv';

    public const FORMAT_PAIN001_XML = 'pain001_xml';

    protected $fillable = [
        'status',
        'bank_format',
        'item_count',
        'total_amount',
        'created_by',
        'approved_by',
        'approved_at',
        'file_disk_path',
        'file_sha256',
        'generated_at',
        'acked_at',
        'ack_file_path',
        'ack_sha256',
        'ack_accepted_count',
        'ack_rejected_count',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'approved_at' => 'datetime',
            'generated_at' => 'datetime',
            'acked_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(DisbursementBatchItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    /**
     * @return array<string, string>
     */
    public static function statusLabels(): array
    {
        return [
            self::STATUS_DRAFT => __('Draft'),
            self::STATUS_PENDING_APPROVAL => __('Pending approval'),
            self::STATUS_APPROVED => __('Approved'),
            self::STATUS_GENERATED => __('Generated'),
            self::STATUS_ACKED => __('Acknowledged'),
            self::STATUS_CLEARED => __('Cleared'),
            self::STATUS_CANCELLED => __('Cancelled'),
        ];
    }

    /**
     * @return array<string, string>
     */
    public static function formatLabels(): array
    {
        return [
            self::FORMAT_AL_RAJHI_CSV => __('Al Rajhi CSV'),
            self::FORMAT_PAIN001_XML => __('pain.001 XML'),
        ];
    }
}
