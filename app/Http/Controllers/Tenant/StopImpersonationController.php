<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\ImpersonationService;
use Filament\Facades\Filament;
use Illuminate\Http\RedirectResponse;

class StopImpersonationController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        if ((int) session('impersonator_user_id') > 0) {
            app(ImpersonationService::class)->stop();
        }

        return redirect(Filament::getPanel('member')?->getUrl() ?? '/member');
    }
}
