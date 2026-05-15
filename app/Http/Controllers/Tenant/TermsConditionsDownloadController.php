<?php

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class TermsConditionsDownloadController extends Controller
{
    public function __invoke(): BinaryFileResponse
    {
        $absolutePath = public_path('downloads/fund-terms-and-conditions.pdf');

        if (! is_file($absolutePath)) {
            abort(404, __('Terms & Conditions document is not available.'));
        }

        return response()->download(
            $absolutePath,
            'fund-terms-and-conditions.pdf',
            [
                'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
                'Pragma' => 'no-cache',
                'Expires' => '0',
            ],
        );
    }
}
