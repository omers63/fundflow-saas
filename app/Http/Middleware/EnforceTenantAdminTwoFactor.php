<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Filament\Tenant\Pages\AdminSecurityPage;
use App\Livewire\Tenant\TenantAdminTwoFactorChallengePage;
use App\Models\Tenant\User;
use App\Support\Security\SecuritySettings;
use App\Support\Security\TwoFactorSession;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final class EnforceTenantAdminTwoFactor
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $panel = Filament::getCurrentOrDefaultPanel();

        if ($panel === null || $panel->getId() !== 'tenant') {
            return $next($request);
        }

        $user = Auth::guard('tenant')->user();

        if (! $user instanceof User || ! $user->is_admin) {
            return $next($request);
        }

        if ($this->isChallengeOrLoginRoute($request)) {
            return $next($request);
        }

        if ($user->hasTwoFactorEnabled() && ! TwoFactorSession::hasPassed()) {
            Auth::guard('tenant')->logout();
            TwoFactorSession::clear();

            return redirect()->to(TenantAdminTwoFactorChallengePage::getUrlPath());
        }

        if (
            SecuritySettings::enforceAdminTwoFactor()
            && ! $user->hasTwoFactorEnabled()
            && ! $this->isSecuritySetupRoute($request)
        ) {
            return redirect()->to(AdminSecurityPage::getUrl());
        }

        return $next($request);
    }

    private function isChallengeOrLoginRoute(Request $request): bool
    {
        return $request->is('admin/login')
            || $request->is('admin/two-factor-challenge')
            || $request->routeIs('filament.tenant.auth.login');
    }

    private function isSecuritySetupRoute(Request $request): bool
    {
        return $request->is('admin/security*')
            || $request->is('admin/admin-security*')
            || str_contains($request->path(), 'admin-security');
    }
}
