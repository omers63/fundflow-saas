<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Services\DatabaseMaintenanceService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class DatabaseBackupDownloadController
{
    public function __invoke(Request $request, DatabaseMaintenanceService $service): BinaryFileResponse|StreamedResponse
    {
        abort_unless($request->user('tenant')?->is_admin === true, 403);

        return $service->downloadBackupResponse();
    }
}
