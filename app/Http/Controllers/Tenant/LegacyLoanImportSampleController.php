<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Support\LegacyMigrationSampleCsv;
use App\Support\Utf8CsvStream;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LegacyLoanImportSampleController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        return response()->streamDownload(function (): void {
            $out = Utf8CsvStream::open();
            fputcsv($out, LegacyMigrationSampleCsv::loanHeaders());
            foreach (LegacyMigrationSampleCsv::loanRows() as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, 'legacy-loans-import-sample.csv', [
            ...Utf8CsvStream::downloadHeaders(),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
