<?php

namespace App\Filament\Resources\Tenants\Pages;

use App\Filament\Resources\Tenants\TenantResource;
use App\Models\Member;
use App\Models\User;
use Filament\Resources\Pages\CreateRecord;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

class CreateTenant extends CreateRecord
{
    protected static string $resource = TenantResource::class;

    protected function handleRecordCreation(array $data): Model
    {
        $domain = $data['primary_domain'];
        $adminName = $data['admin_name'];
        $adminEmail = $data['admin_email'];
        $adminPassword = $data['admin_password'];

        unset($data['primary_domain'], $data['admin_name'], $data['admin_email'], $data['admin_password']);

        if (blank($data['tenancy_db_name'] ?? null)) {
            $data['tenancy_db_name'] = config('tenancy.database.prefix') . $data['id'] . config('tenancy.database.suffix');
        }

        /** @var \App\Models\Tenant $tenant */
        $tenant = static::getModel()::create($data);
        $tenant->domains()->create([
            'domain' => $domain,
        ]);

        tenancy()->initialize($tenant);

        $member = Member::query()->create([
            'full_name' => $adminName,
            'relation' => 'parent',
            'status' => 'active',
            'is_dependent' => false,
        ]);

        User::query()->create([
            'name' => $adminName,
            'email' => $adminEmail,
            'password' => Hash::make($adminPassword),
            'role' => 'admin',
            'member_id' => $member->id,
        ]);

        tenancy()->end();

        return $tenant;
    }
}
