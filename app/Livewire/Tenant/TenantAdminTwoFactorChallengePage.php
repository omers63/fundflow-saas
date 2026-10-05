<?php

declare(strict_types=1);

namespace App\Livewire\Tenant;

use App\Models\Tenant\PortalAccessLog;
use App\Models\Tenant\User;
use App\Services\PortalAccessLogService;
use App\Services\Security\TwoFactorService;
use App\Support\Security\TwoFactorSession;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.tenant-auth')]
#[Title('Two-factor authentication')]
class TenantAdminTwoFactorChallengePage extends Component
{
    public string $code = '';

    public static function getUrlPath(): string
    {
        return url('/admin/two-factor-challenge');
    }

    public function mount(): void
    {
        if (! session()->has(TwoFactorSession::PENDING_USER_ID) && ! Auth::guard('tenant')->check()) {
            $this->redirect(Filament::getPanel('tenant')->getLoginUrl());
        }
    }

    public function verify(): void
    {
        $this->validate([
            'code' => ['required', 'string', 'min:6', 'max:20'],
        ]);

        $pendingId = (int) session(TwoFactorSession::PENDING_USER_ID, 0);
        $user = $pendingId > 0
            ? User::query()->find($pendingId)
            : Auth::guard('tenant')->user();

        if (! $user instanceof User || ! $user->hasTwoFactorEnabled()) {
            TwoFactorSession::clear();

            throw ValidationException::withMessages([
                'code' => __('Two-factor challenge expired. Sign in again.'),
            ]);
        }

        $throttleKey = 'tenant-admin-2fa|'.$user->id;

        if (RateLimiter::tooManyAttempts($throttleKey, 8)) {
            throw ValidationException::withMessages([
                'code' => __('Too many attempts. Try again later.'),
            ]);
        }

        if (! app(TwoFactorService::class)->verifyLoginCode($user, $this->code)) {
            RateLimiter::hit($throttleKey, 300);

            throw ValidationException::withMessages([
                'code' => __('Invalid authenticator or recovery code.'),
            ]);
        }

        RateLimiter::clear($throttleKey);

        $remember = (bool) session(TwoFactorSession::PENDING_REMEMBER, false);
        Auth::guard('tenant')->login($user, $remember);
        session()->regenerate();
        session()->put('locale', $user->preferredLocale());
        TwoFactorSession::markPassed();
        session()->forget([TwoFactorSession::PENDING_USER_ID, TwoFactorSession::PENDING_REMEMBER]);

        app(PortalAccessLogService::class)->record(
            $user,
            PortalAccessLog::PANEL_ADMIN,
            $user->member,
        );

        $this->redirectIntended(Filament::getPanel('tenant')->getUrl());
    }

    public function render(): View
    {
        return view('livewire.tenant.tenant-admin-two-factor-challenge-page');
    }
}
