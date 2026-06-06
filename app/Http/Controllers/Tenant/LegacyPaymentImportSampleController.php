<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LegacyPaymentImportSampleController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $filename = 'legacy-payments-import-sample.csv';

        $headers = [
            'member_email',
            'member_number',
            'payment_date',
            'amount',
            'payment_type',
            'notes',
        ];

        $rows = [
            ['ahmed.import@example.test', 'MEM-1001', '2025-10-05', '500', '', 'Ambiguous — classifier will suggest type'],
            ['fatimah.import@example.test', 'MEM-1002', '2025-11-01', '1000', 'contribution', 'Explicit contribution'],
            ['omar.import@example.test', '', '2025-09-15', '750', 'loan_repayment', 'Explicit loan repayment'],
            ['ahmed.import@example.test', 'MEM-1001', '2024-06-01', '500', 'ignore', 'Before cut-off — skip when using snapshot strategy'],
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
