<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Tenant\ImpersonationService;
use Illuminate\Http\RedirectResponse;

class StopImpersonationController extends Controller
{
    public function __invoke(): RedirectResponse
    {
        $impersonation = app(ImpersonationService::class);
        $returnUrl = $impersonation->returnUrl();

        if ($impersonation->isActive()) {
            $impersonation->stop();
        }

        return redirect($returnUrl);
    }
}
