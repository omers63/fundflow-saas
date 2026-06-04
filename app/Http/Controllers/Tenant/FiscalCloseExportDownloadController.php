<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\FiscalClose;
use App\Services\FiscalClose\FiscalCloseExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class FiscalCloseExportDownloadController extends Controller
{
    public function __invoke(Request $request, FiscalClose $fiscalClose, string $fileKey): BinaryFileResponse
    {
        $user = $request->user('tenant');

        if ($user === null || ! $user->is_admin) {
            abort(403);
        }

        $relativePath = app(FiscalCloseExportService::class)->resolveDownloadPath($fiscalClose, $fileKey);
        $absolutePath = Storage::disk('local')->path($relativePath);

        return response()->download(
            $absolutePath,
            basename($relativePath),
            [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ],
        );
    }
}
