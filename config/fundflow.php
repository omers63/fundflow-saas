<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Filament database notifications polling
    |--------------------------------------------------------------------------
    |
    | How often Livewire polls for new in-app notifications (bell icon).
    | Examples: 5s, 10s, 30s. Set to null to disable polling (not recommended).
    |
    */
    'database_notifications_polling' => env('FILAMENT_DATABASE_NOTIFICATIONS_POLLING', '30s'),

    /*
    |--------------------------------------------------------------------------
    | Fresh-tenant Settings defaults
    |--------------------------------------------------------------------------
    |
    | Canonical defaults for Settings tabs live in App\Support\*Settings::defaults()
    | and are persisted on provision via App\Support\DefaultTenantSettings
    | (wired from Database\Seeders\Tenant\TenantDatabaseSeeder). Fund/loan tiers
    | and bank CSV templates are seeded alongside that seeder.
    |
    */

];
