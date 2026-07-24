<?php

namespace App\Livewire\Tenant;

use App\Models\Tenant\PortalAccessLog;
use App\Models\Tenant\User;
use App\Services\PortalAccessLogService;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts.tenant-auth')]
#[Title('Admin sign in')]
class TenantAdminLoginPage extends Component
{
    public string $email = '';

    public string $password = '';

    public bool $remember = false;

    public function mount(): void
    {
        if (! Auth::guard('tenant')->check()) {
            return;
        }

        $user = Auth::guard('tenant')->user();

        if (! $user instanceof User) {
            Auth::guard('tenant')->logout();

            return;
        }

        $tenantPanel = Filament::getPanel('tenant');
        $memberPanel = Filament::getPanel('member');

        if ($user->canAccessPanel($tenantPanel)) {
            $this->redirect($tenantPanel->getUrl());

            return;
        }

        if ($user->canAccessPanel($memberPanel)) {
            $this->redirect($memberPanel->getUrl());

            return;
        }

        Auth::guard('tenant')->logout();
    }

    /**
     * @return array<string, list<string>>
     */
    protected function rules(): array
    {
        return [
            'email' => ['required', 'email'],
            'password' => ['required', 'string'],
        ];
    }

    public function login(): void
    {
        $this->validate();
        $this->resetErrorBag();

        if (! $this->ensureIsNotRateLimited()) {
            return;
        }

        if (
            ! Auth::guard('tenant')->attempt(
                ['email' => $this->email, 'password' => $this->password],
                $this->remember,
            )
        ) {
            RateLimiter::hit($this->throttleKey(), 300);

            throw ValidationException::withMessages([
                'email' => __('These credentials do not match our records.'),
            ]);
        }

        $user = Auth::guard('tenant')->user();

        if (! $user instanceof User || ! $user->canAccessPanel(Filament::getPanel('tenant'))) {
            Auth::guard('tenant')->logout();

            RateLimiter::hit($this->throttleKey(), 300);

            throw ValidationException::withMessages([
                'email' => __('You do not have access to the admin dashboard.'),
            ]);
        }

        RateLimiter::clear($this->throttleKey());
        session()->regenerate();
        session()->put('locale', $user->preferredLocale());

        app(PortalAccessLogService::class)->record(
            $user,
            PortalAccessLog::PANEL_ADMIN,
            $user->member,
        );

        $this->redirectIntended(Filament::getPanel('tenant')->getUrl());
    }

    public function render(): View
    {
        return view('livewire.tenant.tenant-admin-login-page');
    }

    protected function ensureIsNotRateLimited(): bool
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey(), 5)) {
            return true;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey());

        throw ValidationException::withMessages([
            'email' => __('Too many login attempts. Please try again in :seconds seconds.', [
                'seconds' => $seconds,
            ]),
        ]);
    }

    protected function throttleKey(): string
    {
        return 'tenant-admin-login|'.Str::transliterate(Str::lower($this->email).'|'.request()->ip());
    }
}
