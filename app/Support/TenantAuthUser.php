<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\User;

/**
 * Resolve the authenticated tenant user across Filament’s tenant guard and the default guard.
 */
final class TenantAuthUser
{
    public static function user(): ?User
    {
        $candidates = [
            auth('tenant')->user(),
            auth()->user(),
        ];

        foreach ($candidates as $user) {
            if ($user instanceof User) {
                return $user;
            }
        }

        return null;
    }
}
