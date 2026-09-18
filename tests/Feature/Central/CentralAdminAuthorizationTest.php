<?php

declare(strict_types=1);

use App\Models\Central\Permission;
use App\Models\Central\Role;
use App\Models\Central\Tenant;
use App\Models\Central\User;
use Illuminate\Support\Facades\Gate;

test('central super admin can authorize tenant resource actions', function () {
    $admin = User::factory()->create([
        'email' => 'central-authz-'.uniqid().'@example.com',
    ]);

    $role = Role::query()->firstOrCreate([
        'name' => 'super_admin',
        'guard_name' => 'web',
    ]);

    foreach (['ViewAny:Tenant', 'View:Tenant', 'Create:Tenant', 'Update:Tenant'] as $permission) {
        $role->givePermissionTo(
            Permission::query()->firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ])
        );
    }

    $admin->assignRole($role);

    expect(Gate::forUser($admin)->allows('viewAny', Tenant::class))->toBeTrue();

    $tenant = Tenant::query()->first();
    if ($tenant !== null) {
        expect(Gate::forUser($admin)->allows('view', $tenant))->toBeTrue();
    }
});

test('central super admin tenants index is authorized', function () {
    $domain = config('tenancy.central_domain');
    $admin = User::factory()->create([
        'email' => 'central-tenants-'.uniqid().'@example.com',
    ]);

    $role = Role::query()->firstOrCreate([
        'name' => 'super_admin',
        'guard_name' => 'web',
    ]);
    $admin->assignRole($role);

    $this->actingAs($admin)
        ->get('http://'.$domain.'/admin/tenants')
        ->assertSuccessful();
});

test('tenant admin authorization does not require hasRole', function () {
    $tenantUser = new App\Models\Tenant\User;

    expect(method_exists($tenantUser, 'hasRole'))->toBeFalse()
        ->and(config('filament-shield.super_admin.define_via_gate'))->toBeFalse();

    // Gate::before must not call hasRole() on tenant users.
    expect(fn () => Gate::forUser($tenantUser)->check('viewAny', Tenant::class))
        ->not->toThrow(BadMethodCallException::class);
});
