<?php

namespace App\Http\Responses;

use App\Services\Tenant\ImpersonationService;
use Filament\Auth\Http\Responses\Contracts\LogoutResponse as Responsable;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class FilamentMemberLogoutResponse implements Responsable
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        if ((int) $request->session()->get('impersonator_user_id') > 0) {
            app(ImpersonationService::class)->stop();

            return redirect(Filament::getPanel('member')->getUrl());
        }

        return redirect()->route('filament.member.auth.login');
    }
}
