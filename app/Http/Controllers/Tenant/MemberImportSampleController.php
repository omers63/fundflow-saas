<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\MemberExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class MemberImportSampleController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $filename = 'members-import-sample.csv';

        $headers = MemberExportService::csvHeaders();

        $rows = [
            ['MEM-1001', 'Ahmed Al Saud', 'ahmed.import@example.test', '0501000101', '500', '2024-06-01', 'active', '', '', '2024-12-31', '1500', '8000', '', ''],
            ['MEM-1002', 'Fatimah Hassan', 'fatimah.import@example.test', '0501000102', '1000', '2025-01-15', 'active', 'MEM-1001', 'ahmed.import@example.test', '', '0', '0', '', ''],
            ['', 'Omar Mansour', 'omar.import@example.test', '', '1500', '2026-01-01', 'active', '', '', '2025-12-31', '250', '4200', '', ''],
        ];

        return response()->streamDownload(function () use ($headers, $rows): void {
            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);
            foreach ($rows as $row) {
                fputcsv($out, $row);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
