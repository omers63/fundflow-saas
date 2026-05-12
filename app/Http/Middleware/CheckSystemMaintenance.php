<?php

namespace App\Http\Middleware;

use App\Support\SystemSettings;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSystemMaintenance
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $isMaintenanceEnabled = (bool) SystemSettings::get('maintenance_enabled', false);

        if (!$isMaintenanceEnabled) {
            return $next($request);
        }

        $allowed = [
            'admin*',
            'member*',
            'livewire*',
            'filament*',
            'lang/*',
            'up',
            'storage/*',
            'tenancy/assets/*',
        ];

        if ($request->is($allowed)) {
            return $next($request);
        }

        return response()->view('errors.maintenance', [
            'message' => SystemSettings::get('maintenance_message', 'We are performing scheduled maintenance.'),
        ], 503);
    }
}
