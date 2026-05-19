<?php

namespace App\Support;

use BadMethodCallException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;

class AuthSessionPasswordHash
{
    public static function syncForUser(Authenticatable $user, string $guard): void
    {
        $guardInstance = Auth::guard($guard);

        $passwordHash = $user->getAuthPassword();

        try {
            $passwordHash = $guardInstance->hashPasswordForCookie($passwordHash);
        } catch (BadMethodCallException) {
        }

        session()->put('password_hash_'.$guard, $passwordHash);

        $defaultGuard = (string) config('auth.defaults.guard');

        if ($defaultGuard !== '' && $defaultGuard !== $guard) {
            session()->put('password_hash_'.$defaultGuard, $passwordHash);
        }
    }
}
