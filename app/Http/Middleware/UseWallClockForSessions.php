<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Carbon\Carbon;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Auth/session cookies and CSRF must never use a shifted Carbon test clock.
 */
class UseWallClockForSessions
{
    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        Carbon::setTestNow();

        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        Carbon::setTestNow();
    }
}
