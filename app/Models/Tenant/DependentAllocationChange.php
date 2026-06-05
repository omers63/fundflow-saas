<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DependentAllocationChange extends Model
{
    protected $fillable = [
        'parent_member_id',
        'dependent_member_id',
        'old_amount',
        'new_amount',
        'changed_by_user_id',
        'note',
    ];

    protected function casts(): array
    {
        return [
            'old_amount' => 'integer',
            'new_amount' => 'integer',
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

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by_user_id');
    }

    public function isIncrease(): bool
    {
        return $this->new_amount > $this->old_amount;
    }

    public function isDecrease(): bool
    {
        return $this->new_amount < $this->old_amount;
    }

    public function delta(): int
    {
        return $this->new_amount - $this->old_amount;
    }

    public function deltaLabel(string $currency): string
    {
        $delta = $this->delta();
        $prefix = $delta > 0 ? '+' : '';

        return $prefix.number_format(abs($delta), 0).' '.$currency;
    }
}
