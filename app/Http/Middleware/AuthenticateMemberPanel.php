<?php

namespace App\Http\Middleware;

use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Services\Tenant\ImpersonationService;
use App\Support\AuthSessionPasswordHash;
use App\Support\MemberMembershipPolicy;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AuthenticateMemberPanel extends AuthenticateFilamentPanel
{
    /**
     * @param  Request  $request
     * @param  array<string>  $guards
     */
    public function handle($request, Closure $next, ...$guards)
    {
        if (
            $request->isMethod('post')
            && $request->path() === 'member/logout'
            && (int) $request->session()->get('impersonator_user_id') > 0
        ) {
            $impersonation = app(ImpersonationService::class);
            $returnUrl = $impersonation->returnUrl();
            $impersonation->stop();

            return redirect($returnUrl);
        }

        return parent::handle($request, $next, ...$guards);
    }

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
                    AuthSessionPasswordHash::syncForUser($impersonatedUser, $guardName);

                    $impersonatedMemberId = (int) $request->session()->get('impersonated_member_id');

                    if ($impersonatedMemberId > 0) {
                        $request->session()->put('active_member_id', $impersonatedMemberId);
                    } elseif ($impersonatedUser->member !== null) {
                        $request->session()->put('active_member_id', $impersonatedUser->member->id);
                    }
                }
            }
        }

        // Special member portal block (withdrawn / suspended, etc.) before the
        // default “no panel access → login redirect” behaviour.
        if ($guard->check()) {
            $authUser = $guard->user();

            if (
                $panel !== null
                && $panel->getId() === 'member'
                && $authUser instanceof User
                && ($member = $authUser->activeMember()) !== null
                && app(MemberMembershipPolicy::class)->isPortalAccessBlocked($member)
                                && ! app(ImpersonationService::class)->isAdminImpersonatingUser($authUser)
            ) {
                $memberStatus = $member->status;
                $guard->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();
                $request->session()->flash(Member::portalBlockedSessionKey($memberStatus), true);

                redirect()->route('filament.member.auth.login')->send();

                exit;
            }
        }

        parent::authenticate($request, $guards);

        Auth::shouldUse($guardName);
    }

    protected function redirectTo($request): ?string
    {
        return Filament::getPanel('member')?->getLoginUrl();
    }
}
