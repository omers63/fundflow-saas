<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MemberRiskOutcome extends Model
{
    protected $fillable = [
        'member_id',
        'rules_score',
        'rules_band',
        'defaulted',
        'label_source',
        'features',
        'labeled_at',
    ];

    protected function casts(): array
    {
        return [
            'defaulted' => 'boolean',
            'features' => 'array',
            'labeled_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }
}
