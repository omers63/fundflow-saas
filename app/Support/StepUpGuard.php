<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\User;
use Illuminate\Support\Facades\Hash;
use InvalidArgumentException;

final class StepUpGuard
{
    public const SESSION_KEY = 'step_up_confirmed_at';

    public function ttlMinutes(): int
    {
        return max(1, (int) config('auth.step_up_ttl_minutes', env('STEP_UP_TTL_MINUTES', 15)));
    }

    public function isConfirmed(): bool
    {
        $confirmedAt = session(self::SESSION_KEY);

        if (! is_numeric($confirmedAt)) {
            return false;
        }

        return now()->timestamp - (int) $confirmedAt <= ($this->ttlMinutes() * 60);
    }

    public function assertConfirmed(): void
    {
        if (! $this->isConfirmed()) {
            throw new InvalidArgumentException(__('Step-up authentication required.'));
        }
    }

    public function confirm(User $user, string $password): void
    {
        if (! Hash::check($password, $user->password)) {
            throw new InvalidArgumentException(__('Password confirmation failed.'));
        }

        session([self::SESSION_KEY => now()->timestamp]);
    }

    public function clear(): void
    {
        session()->forget(self::SESSION_KEY);
    }
}
