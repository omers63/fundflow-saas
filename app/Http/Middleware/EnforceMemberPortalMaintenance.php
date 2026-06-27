<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\MemberPortalMaintenance;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceMemberPortalMaintenance
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! MemberPortalMaintenance::isEnabled()) {
            return $next($request);
        }

        if (MemberPortalMaintenance::isExempt($request)) {
            return $next($request);
        }

        $guard = Auth::guard('tenant');

        if (! $guard->check()) {
            return $next($request);
        }

        if (MemberPortalMaintenance::sessionEpochIsValid()) {
            return $next($request);
        }

        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        $request->session()->flash(MemberPortalMaintenance::MAINTENANCE_NOTICE_SESSION_KEY, true);

        $loginUrl = Filament::getPanel('member')?->getLoginUrl() ?? '/member/login';

        return redirect()->to($loginUrl);
    }
}
