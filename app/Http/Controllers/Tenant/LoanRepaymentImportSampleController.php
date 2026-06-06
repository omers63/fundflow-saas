<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\Loans\LoanRepaymentExportService;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LoanRepaymentImportSampleController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $filename = 'loan-repayments-import-sample.csv';

        $headers = LoanRepaymentExportService::csvHeaders();

        $rows = [
            ['legacy', '101', 'MEM-001', 'Sample Borrower One', 'borrower001@fundflow-import.example', '', '2500', '', '2025-03-15 10:00:00', '', 'Bulk historical repayment before go-live'],
            ['installment', '102', 'MEM-002', 'Sample Borrower Two', 'borrower002@fundflow-import.example', '3', '1200', '0', '2025-04-01 09:30:00', '2025-04-05', ''],
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
