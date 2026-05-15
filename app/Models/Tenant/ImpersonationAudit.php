<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ImpersonationAudit extends Model
{
    protected $fillable = [
        'impersonator_user_id',
        'impersonated_user_id',
        'impersonated_member_id',
        'event',
        'ip_address',
        'user_agent',
        'meta',
        'occurred_at',
    ];

    protected function casts(): array
    {
        return [
            'meta' => 'array',
            'occurred_at' => 'datetime',
        ];
    }

    public function impersonator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonator_user_id');
    }

    public function impersonatedUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'impersonated_user_id');
    }

    public function impersonatedMember(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'impersonated_member_id');
    }
}
