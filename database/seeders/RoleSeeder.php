<?php

namespace Database\Seeders;

use App\Models\Central\Permission;
use App\Models\Central\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // 1. Create Roles
        $superAdminRole = Role::firstOrCreate(['name' => 'super_admin', 'guard_name' => 'web']);
        $userRole = Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

        // 2. Define Permissions for 'user' role
        // Note: Format MUST match filament-shield config (PascalCase with Colon)
        $userPermissions = [
            // Tenant
            'ViewAny:Tenant',
            'View:Tenant',
            'Create:Tenant',
            'Update:Tenant',

            // Invoice (View Only)
            'ViewAny:Invoice',
            'View:Invoice',

            // Subscription (View Only)
            'ViewAny:Subscription',
            'View:Subscription',

            // Plan (View Only)
            'ViewAny:Plan',
            'View:Plan',
        ];

        // 3. Create Permissions if they don't exist & Assign to User Role
        $permissions = [];
        foreach ($userPermissions as $permissionName) {
            $permission = Permission::firstOrCreate(['name' => $permissionName, 'guard_name' => 'web']);
            $permissions[] = $permission;
        }

        $userRole->syncPermissions($permissions);

        // Keep an explicit full permission set on super_admin as well as Gate::before
        // (filament-shield.super_admin.define_via_gate), so navigation still works if
        // the gate bypass is disabled or the permission cache is stale.
        $allPermissions = Permission::query()->where('guard_name', 'web')->get();
        $superAdminRole->syncPermissions($allPermissions);
    }
}
