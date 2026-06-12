<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Support\LegacyMigrationSampleCsv;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LegacyLoanImportSampleController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, LegacyMigrationSampleCsv::loanHeaders());
            foreach (LegacyMigrationSampleCsv::loanRows() as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'legacy-loans-import-sample.csv', [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
