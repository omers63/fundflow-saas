<?php

namespace App\Models\Central;

use Filament\Models\Contracts\HasName;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\CentralConnection;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

/**
 * Tenant model that integrates Stancl Tenancy with Filament.
 * Supports multi-database and multi-domain.
 */
class Tenant extends BaseTenant implements HasName, TenantWithDatabase
{
    use CentralConnection, HasDatabase, HasDomains, HasFactory;

    /**
     * Defines custom columns stored in the 'tenants' table (Central DB).
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'central_user_id', // Tenant owner (User in Central DB)
            'plan_id',         // Plan selected during registration
            'is_provisioned',  // Flag indicating if DB & Resources are ready
            'provisioned_at',
            'name',            // Studio / Business Name
        ];
    }

    /**
     * Used by Filament to display the Tenant label.
     */
    public function getFilamentName(): string
    {
        return $this->name ?? $this->id;
    }

    /**
     * The Central User who owns this tenant.
     */
    public function owner()
    {
        return $this->belongsTo(User::class, 'central_user_id');
    }

    /**
     * The plan used by the tenant.
     */
    public function plan()
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Tenant subscription details.
     */
    public function subscription()
    {
        return $this->hasOne(Subscription::class);
    }
}
