<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DependentCashAllocation extends Model
{
    protected $fillable = [
        'parent_member_id',
        'dependent_member_id',
        'allocation_month',
        'allocation_year',
        'amount',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
        ];
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'parent_member_id');
    }

    public function dependent(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'dependent_member_id');
    }
}
