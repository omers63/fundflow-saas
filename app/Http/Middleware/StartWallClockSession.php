<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Illuminate\Contracts\Session\Session;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Date;
use Symfony\Component\HttpFoundation\Response;

/**
 * Session cookies must expire on real wall time, never a shifted Carbon test clock
 * (e.g. tenant business-day testing).
 */
class StartWallClockSession extends StartSession
{
    protected function getCookieExpirationDate(): \DateTimeInterface|int
    {
        if ($this->manager->getSessionConfig()['expire_on_close']) {
            return 0;
        }

        return Date::instance(
            Carbon::createFromTimestamp(time() + $this->getSessionLifetimeInSeconds()),
        );
    }

    protected function addCookieToResponse(Response $response, Session $session): void
    {
        Carbon::setTestNow();

        parent::addCookieToResponse($response, $session);
    }
}
