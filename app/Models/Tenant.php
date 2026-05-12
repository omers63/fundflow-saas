<?php

namespace App\Models;

use Stancl\Tenancy\Contracts\TenantWithDatabase;
use Stancl\Tenancy\Database\Concerns\HasDatabase;
use Stancl\Tenancy\Database\Concerns\HasDomains;
use Stancl\Tenancy\Database\Models\Tenant as BaseTenant;

class Tenant extends BaseTenant implements TenantWithDatabase
{
    use HasDatabase;
    use HasDomains;

    protected $fillable = [
        'id',
        'tenancy_db_name',
        'name',
        'slug',
    ];

    /**
     * Real table columns (everything else is stored in the JSON `data` column by VirtualColumn).
     */
    public static function getCustomColumns(): array
    {
        return [
            'id',
            'name',
            'slug',
            'tenancy_db_name',
        ];
    }
}
