<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProfitDistribution extends Model
{
    public const STATUS_DRAFT = 'draft';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    public const STATUS_CANCELLED = 'cancelled';

    public const METHOD_MONTH_END_FUND = 'month_end_fund_balance';

    protected $fillable = [
        'status',
        'period_start',
        'period_end',
        'amount',
        'allocation_method',
        'motion_id',
        'item_count',
        'posted_total',
        'created_by',
        'approved_by',
        'approved_at',
        'posted_at',
        'reversed_at',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'period_start' => 'date',
            'period_end' => 'date',
            'amount' => 'decimal:2',
            'posted_total' => 'decimal:2',
            'approved_at' => 'datetime',
            'posted_at' => 'datetime',
            'reversed_at' => 'datetime',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ProfitDistributionItem::class);
    }

    public function motion(): BelongsTo
    {
        return $this->belongsTo(Motion::class);
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
            self::STATUS_APPROVED => __('Approved'),
            self::STATUS_POSTED => __('Posted'),
            self::STATUS_REVERSED => __('Reversed'),
            self::STATUS_CANCELLED => __('Cancelled'),
        ];
    }
}
