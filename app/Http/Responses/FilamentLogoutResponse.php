<?php

namespace App\Http\Responses;

use Filament\Auth\Http\Responses\Contracts\LogoutResponse as Responsable;
use Filament\Auth\Http\Responses\LogoutResponse as DefaultLogoutResponse;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;
use Livewire\Features\SupportRedirects\Redirector;

class FilamentLogoutResponse implements Responsable
{
    public function toResponse($request): RedirectResponse|Redirector
    {
        if (Filament::getCurrentPanel()?->getId() === 'member') {
            return app(FilamentMemberLogoutResponse::class)->toResponse($request);
        }

        return app(DefaultLogoutResponse::class)->toResponse($request);
    }
}
