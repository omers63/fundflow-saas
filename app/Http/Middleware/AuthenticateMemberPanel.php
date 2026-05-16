<?php

namespace App\Http\Middleware;

use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use Filament\Facades\Filament;
use Filament\Http\Middleware\Authenticate;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Support\Facades\Auth;

class AuthenticateMemberPanel extends Authenticate
{
    protected function authenticate($request, array $guards): void
    {
        $panel = Filament::getCurrentOrDefaultPanel();
        $guardName = $panel?->getAuthGuard() ?? 'tenant';
        $guard = Auth::guard($guardName);

        if (! $guard->check()) {
            $impersonatedUserId = (int) $request->session()->get('impersonated_user_id');
            if ($impersonatedUserId > 0) {
                $impersonatedUser = User::find($impersonatedUserId);
                if ($impersonatedUser instanceof User && $panel !== null && $impersonatedUser->canAccessPanel($panel)) {
                    $guard->login($impersonatedUser);
                }
            }
        }

        parent::authenticate($request, $guards);

        Auth::shouldUse($guardName);

        $authUser = $guard->user();
        if ($authUser instanceof FilamentUser && $panel !== null && ! $authUser->canAccessPanel($panel)) {
            if (
                $panel->getId() === 'member'
                && $authUser instanceof User
                && $authUser->member !== null
                && in_array($authUser->member->status, Member::PORTAL_BLOCKED_STATUSES, true)
            ) {
                $memberStatus = $authUser->member->status;
                $guard->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $request->session()->flash(Member::portalBlockedSessionKey($memberStatus), true);

                redirect()->route('filament.member.auth.login')->send();

                exit;
            }

            abort(403);
        }
    }

    protected function redirectTo($request): ?string
    {
        return Filament::getPanel('member')?->getLoginUrl();
    }
}
