<?php

declare(strict_types=1);

namespace App\Session;

use Illuminate\Session\DatabaseSessionHandler;

/**
 * Session expiry and last_activity must always follow real wall time, never
 * a tenant business-day override or Carbon test clock.
 */
class WallClockDatabaseSessionHandler extends DatabaseSessionHandler
{
    protected function expired($session): bool
    {
        return isset($session->last_activity)
            && $session->last_activity < (time() - ($this->minutes * 60));
    }

    protected function currentTime(): int
    {
        return time();
    }
}
