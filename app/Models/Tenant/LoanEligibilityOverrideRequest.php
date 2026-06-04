<?php

declare(strict_types=1);

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Schema;

class LoanEligibilityOverrideRequest extends Model
{
    protected $fillable = [
        'member_id',
        'failed_gates',
        'member_message',
        'status',
        'admin_remarks',
        'reviewed_by',
        'reviewed_at',
    ];

    protected function casts(): array
    {
        return [
            'failed_gates' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public static function isTableReady(): bool
    {
        return once(function (): bool {
            try {
                return Schema::hasTable('loan_eligibility_override_requests');
            } catch (\Throwable) {
                return false;
            }
        });
    }

    /**
     * @return list<string>
     */
    public function gateKeys(): array
    {
        return array_keys($this->failed_gates ?? []);
    }
}
