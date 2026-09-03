<?php

namespace App\Http\Responses;

use App\Services\Tenant\ImpersonationService;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse as Responsable;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class FilamentMemberLogoutResponse implements Responsable
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        $impersonation = app(ImpersonationService::class);

        if ($impersonation->isActive()) {
            $returnUrl = $impersonation->returnUrl();
            $impersonation->stop();

            return redirect($returnUrl);
        }

        return redirect()->route('filament.member.auth.login');
    }
}
