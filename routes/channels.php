<?php

use App\Models\Central\User as CentralUser;
use App\Models\Tenant\User as TenantUser;
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('App.Models.Tenant.User.{id}', function (TenantUser $user, string $id): bool {
    return (int) $user->getKey() === (int) $id;
});

Broadcast::channel('App.Models.Central.User.{id}', function (CentralUser $user, string $id): bool {
    return (int) $user->getKey() === (int) $id;
});
