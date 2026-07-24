<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

class PortalAccessLog extends Model
{
    use SoftDeletes;

    public const PANEL_MEMBER = 'member';

    public const PANEL_ADMIN = 'admin';

    protected $fillable = [
        'user_id',
        'member_id',
        'member_name',
        'panel',
        'ip_address',
        'user_agent',
        'accessed_at',
    ];

    protected function casts(): array
    {
        return [
            'accessed_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function displayName(): string
    {
        if (filled($this->member_name)) {
            return (string) $this->member_name;
        }

        return $this->member?->name
            ?? $this->user?->name
            ?? __('Unknown');
    }
}
