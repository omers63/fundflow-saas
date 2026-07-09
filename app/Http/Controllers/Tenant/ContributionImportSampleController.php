<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\ContributionExportService;
use App\Support\Utf8CsvStream;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ContributionImportSampleController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $filename = 'contributions-import-sample.csv';

        $headers = ContributionExportService::csvHeaders();

        $rows = [
            ['MEM-001', 'Sample Member One', 'member001@fundflow-import.example', '2025-01-01', '5000', '5000', '5000', 'posted', 'collected', '0', '2025-01-15 10:00:00', '2025-01-15 10:00:00', 'import_csv', 'REF-001', 'Historical import — posted'],
            ['MEM-002', 'Sample Member Two', 'member002@fundflow-import.example', '2025-02-01', '5000', '5000', '0', 'pending', 'pending', '', '', '', 'import_csv', '', 'Awaiting collection'],
            ['MEM-003', 'Sample Member Three', 'member003@fundflow-import.example', '2024-11-01', '5000', '5000', '0', 'waived', 'pending', '', '', '', 'import_csv', '', 'Arrears waived during migration'],
        ];

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = Utf8CsvStream::open();
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            ...Utf8CsvStream::downloadHeaders(),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
