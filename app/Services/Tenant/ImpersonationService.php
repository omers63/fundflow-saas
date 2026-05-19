<?php

namespace App\Services\Tenant;

use App\Models\Tenant\ImpersonationAudit;
use App\Models\Tenant\Member;
use App\Models\Tenant\User;
use App\Support\AuthSessionPasswordHash;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;

class ImpersonationService
{
    public function start(User $impersonator, User $impersonatedUser, ?Member $impersonatedMember = null): void
    {
        $originalUserId = session('impersonator_user_id', $impersonator->id);

        session([
            'impersonator_user_id' => $originalUserId,
            'impersonated_user_id' => $impersonatedUser->id,
            'impersonated_member_id' => $impersonatedMember?->id,
            'impersonation_started_at' => now()->toDateTimeString(),
            'active_member_id' => $impersonatedMember?->id ?? $impersonatedUser->member?->id,
        ]);

        ImpersonationAudit::create([
            'impersonator_user_id' => $impersonator->id,
            'impersonated_user_id' => $impersonatedUser->id,
            'impersonated_member_id' => $impersonatedMember?->id,
            'event' => 'started',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'meta' => ['started_from' => 'member_dependents'],
            'occurred_at' => now(),
        ]);

        $guard = $this->memberGuard();

        Auth::shouldUse($guard);
        Auth::guard($guard)->login($impersonatedUser);
        AuthSessionPasswordHash::syncForUser($impersonatedUser, $guard);
    }

    public function stop(): bool
    {
        $impersonatorId = (int) session('impersonator_user_id');
        $impersonatedUserId = (int) session('impersonated_user_id');
        $impersonatedMemberId = session('impersonated_member_id');

        if ($impersonatorId <= 0) {
            return false;
        }

        $impersonator = User::find($impersonatorId);
        if ($impersonator === null) {
            return false;
        }

        ImpersonationAudit::create([
            'impersonator_user_id' => $impersonatorId,
            'impersonated_user_id' => $impersonatedUserId ?: $impersonatorId,
            'impersonated_member_id' => is_numeric($impersonatedMemberId) ? (int) $impersonatedMemberId : null,
            'event' => 'stopped',
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'meta' => ['stopped_from' => 'member_profile'],
            'occurred_at' => now(),
        ]);

        $guard = $this->memberGuard();

        Auth::shouldUse($guard);
        Auth::guard($guard)->login($impersonator);
        AuthSessionPasswordHash::syncForUser($impersonator, $guard);

        session()->forget([
            'impersonator_user_id',
            'impersonated_user_id',
            'impersonated_member_id',
            'impersonation_started_at',
        ]);

        if ($impersonator->member !== null) {
            session(['active_member_id' => $impersonator->member->id]);
        } else {
            session()->forget('active_member_id');
        }

        return true;
    }

    private function memberGuard(): string
    {
        return Filament::getPanel('member')?->getAuthGuard() ?? 'tenant';
    }
}
