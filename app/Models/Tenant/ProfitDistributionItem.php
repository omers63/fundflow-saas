<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProfitDistributionItem extends Model
{
    public const STATUS_PREVIEW = 'preview';

    public const STATUS_POSTED = 'posted';

    public const STATUS_REVERSED = 'reversed';

    protected $fillable = [
        'profit_distribution_id',
        'member_id',
        'weight',
        'amount',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'weight' => 'decimal:6',
            'amount' => 'decimal:2',
        ];
    }

    public function distribution(): BelongsTo
    {
        return $this->belongsTo(ProfitDistribution::class, 'profit_distribution_id');
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
