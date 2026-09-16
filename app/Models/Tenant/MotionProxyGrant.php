<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MotionProxyGrant extends Model
{
    protected $fillable = [
        'motion_id',
        'grantor_member_id',
        'proxy_member_id',
        'granted_at',
        'revoked_at',
    ];

    protected function casts(): array
    {
        return [
            'granted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    public function motion(): BelongsTo
    {
        return $this->belongsTo(Motion::class);
    }

    public function grantor(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'grantor_member_id');
    }

    public function proxy(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'proxy_member_id');
    }

    public function isActive(): bool
    {
        return $this->revoked_at === null;
    }
}
