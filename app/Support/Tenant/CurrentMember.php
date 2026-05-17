<?php

declare(strict_types=1);

namespace App\Support\Tenant;

use App\Models\Tenant\Member;
use App\Models\Tenant\User;

final class CurrentMember
{
    public static function get(): ?Member
    {
        $user = auth('tenant')->user();

        if (! $user instanceof User) {
            return null;
        }

        return $user->activeMember();
    }

    public static function id(): ?int
    {
        return self::get()?->id;
    }
}
