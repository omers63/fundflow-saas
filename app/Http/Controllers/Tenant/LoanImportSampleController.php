<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LoanImportSampleController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        $filename = 'loans-import-sample-10.csv';

        $headers = [
            'loan_status',
            'member_email',
            'member_number',
            'national_id',
            'member_name',
            'amount_requested',
            'amount_approved',
            'member_portion',
            'master_portion',
            'purpose',
            'applied_at',
            'approved_at',
            'disbursed_at',
            'settled_at',
            'is_emergency',
            'loan_tier_number',
            'fund_tier_number',
            'settlement_threshold',
            'installments_count',
            'paid_installments_count',
            'total_amount_repaid',
            'guarantor_member_number',
            'guarantor_name',
        ];

        $rows = [
            ['pending', 'member001@fundflow-import.example', '', '', '', '22000', '', '', '', 'Vehicle repair — awaiting committee', '2026-01-08', '', '', '', '0', '', '', '', '12', '', '', '', ''],
            ['pending', 'member002@fundflow-import.example', '', '', '', '8000', '8000', '', '', 'Pre-scored amount; still pending approval', '2026-01-12', '', '', '', '0', '', '', '', '12', '', '', '', ''],
            ['approved', 'member003@fundflow-import.example', '', '', '', '', '15000', '', '', 'Approved queue import (not disbursed)', '2025-12-01', '2025-12-18', '', '', '0', '', '', '0.16', '20', '', '', '', ''],
            ['approved', 'member004@fundflow-import.example', '', '', '', '', '3200', '', '', 'Emergency tier — approved only', '2025-11-20', '2025-11-25', '', '', '1', '', '', '0.16', '8', '', '', '', ''],
            ['active', 'member005@fundflow-import.example', '', '', '', '', '12000', '4800', '7200', 'Active: disbursed; no repayments yet', '2025-10-01', '2025-10-02', '2025-10-05', '', '0', '', '', '0.16', '', '0', '', ''],
            ['active', 'member006@fundflow-import.example', '', '', '', '', '20000', '12000', '8000', 'Active: partially repaid (5 months)', '2025-08-01', '2025-08-02', '2025-08-07', '', '0', '', '', '0.16', '', '5', '', ''],
            ['completed', 'member007@fundflow-import.example', '', '', '', '', '10000', '3500', '6500', 'Historical loan — fully repaid (closed)', '2022-02-10', '2022-02-12', '2022-02-15', '2023-04-01', '0', '', '', '0.16', '10', '', '10000', '', ''],
            ['early_settled', '', '', '', 'Amina Yusuf', '', '9000', '4000', '5000', 'Settled early per fund rules (matched by member_name)', '2021-05-01', '2021-05-03', '2021-05-08', '2022-03-15', '0', '', '', '0.16', '9', '', '9000', '', ''],
            ['active', 'member009@fundflow-import.example', '', '', '', '', '4000', '4000', '0', 'Emergency disbursement — member-funded slice only', '2025-11-01', '2025-11-02', '2025-11-04', '', '1', '', '', '0.16', '6', '1', '', '', ''],
            ['approved', 'member010@fundflow-import.example', '', '', '', '', '11000', '', '', 'Second standard approval — custom horizon', '2025-10-15', '2025-10-22', '', '', '0', '', '', '0.14', '16', '', '', '', ''],
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
