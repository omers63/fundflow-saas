<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Model;

class Member extends Model
{
    protected $fillable = [
        'family_id',
        'parent_member_id',
        'full_name',
        'relation',
        'date_of_birth',
        'national_id',
        'is_dependent',
        'status',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'is_dependent' => 'boolean',
        ];
    }

    public function family(): BelongsTo
    {
        return $this->belongsTo(Family::class);
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_member_id');
    }

    public function dependents(): HasMany
    {
        return $this->hasMany(self::class, 'parent_member_id');
    }
}
