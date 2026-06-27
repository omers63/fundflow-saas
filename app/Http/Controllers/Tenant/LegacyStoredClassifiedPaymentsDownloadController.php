<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Models\Tenant\Setting;
use App\Services\LegacyMigration\LegacyPaymentClassifierService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LegacyStoredClassifiedPaymentsDownloadController extends Controller
{
    public function __invoke(Request $request): BinaryFileResponse|StreamedResponse
    {
        $user = $request->user('tenant');

        if ($user === null || ! $user->is_admin) {
            abort(403);
        }

        if ((string) Setting::get('legacy_migration', 'classify_status', 'idle') !== 'completed') {
            abort(404, __('Classified payments are not ready. Run Classify Payments and wait until it finishes.'));
        }

        $relativePath = LegacyPaymentClassifierService::CLASSIFIED_PAYMENTS_DISK_PATH;

        abort_unless(Storage::disk('local')->exists($relativePath), 404);

        return Storage::disk('local')->download(
            $relativePath,
            'legacy-payments-classified.csv',
            [
                'Content-Type' => 'text/csv',
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
            ],
        );
    }
}
