<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Subscription model connects a Tenant with a Plan.
 * Manages the subscription lifecycle (active, expired, upgraded, etc.).
 */
class Subscription extends Model
{
    use CentralConnection, HasFactory;

    protected $fillable = [
        'tenant_id',
        'plan_id',
        'status', // active, pending, cancelled, expired, pending_upgrade, pending_extension
        'starts_at',
        'ends_at',
        'trial_ends_at',
        'cancels_at',
        'canceled_at',
        'upgrade_info', // Stores target plan data during the upgrade process
        'extension_info', // Stores additional duration data during extension
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'trial_ends_at' => 'datetime',
        'cancels_at' => 'datetime',
        'canceled_at' => 'datetime',
        'upgrade_info' => 'array',
        'extension_info' => 'array',
    ];

    /**
     * The tenant that owns this subscription.
     */
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    /**
     * The active plan.
     */
    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Checks if the subscription is still active.
     */
    public function isActive(): bool
    {
        return $this->status === 'active' && ($this->ends_at === null || $this->ends_at->isFuture());
    }
}
