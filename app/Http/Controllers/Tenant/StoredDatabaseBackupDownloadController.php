<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Models\Tenant\DatabaseBackup;
use App\Services\DatabaseMaintenanceService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class StoredDatabaseBackupDownloadController
{
    public function __invoke(Request $request, DatabaseBackup $databaseBackup): BinaryFileResponse
    {
        abort_unless($request->user('tenant')?->is_admin === true, 403);

        $relativeRoot = app(DatabaseMaintenanceService::class)->backupDirectoryRelative();
        $root = realpath(storage_path('app/'.$relativeRoot));
        abort_unless($root !== false && is_dir($root), 404);

        $full = realpath(storage_path('app/'.$databaseBackup->path));
        abort_unless($full !== false && is_file($full), 404);
        abort_unless(str_starts_with($full, $root), 403);

        return response()->download($full, $databaseBackup->filename, [
            'Content-Type' => str_ends_with($databaseBackup->filename, '.sql')
                ? 'application/sql'
                : 'application/octet-stream',
        ]);
    }
}
