<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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

        // Super Admin gets all permissions implicitly via Gate::before or Shield's logic,
        // but typically Shield handles super_admin having full access automatically.
    }
}
