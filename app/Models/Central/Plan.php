<?php

namespace App\Models\Central;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Stancl\Tenancy\Database\Concerns\CentralConnection;

/**
 * Plan model defines the available subscription packages.
 * This table is intentionally non-opinionated to provide flexibility for developers.
 */
class Plan extends Model
{
    use CentralConnection, HasFactory;

    protected $fillable = [
        'name',
        'slug',
        'price',
        'currency',
        'billing_cycle',
        'duration_months',
        'description',
        'data', // JSON column to store custom limits (e.g., max_users, features_list)
        'is_active',
        'is_custom',
        'support_custom_domain',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'data' => 'array', // Automatically cast JSON to PHP array
        'is_active' => 'boolean',
        'is_custom' => 'boolean',
        'support_custom_domain' => 'boolean',
    ];

    /**
     * Relationship to Tenants using this Plan.
     */
    public function tenants()
    {
        return $this->hasMany(Tenant::class);
    }
}
