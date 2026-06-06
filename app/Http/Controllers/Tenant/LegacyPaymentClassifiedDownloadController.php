<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Services\LegacyMigration\LegacyPaymentClassifierService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LegacyPaymentClassifiedDownloadController extends Controller
{
    public function __invoke(Request $request, LegacyPaymentClassifierService $classifier): StreamedResponse
    {
        $request->validate([
            'source' => ['required', 'string'],
            'cutoff' => ['nullable', 'date'],
        ]);

        $relative = ltrim((string) $request->query('source'), '/');
        $absolute = storage_path('app/' . $relative);

        abort_unless(is_readable($absolute), 404);

        $cutoff = filled($request->query('cutoff'))
            ? Carbon::parse((string) $request->query('cutoff'))
            : null;

        $result = $classifier->classifyFile($absolute, $cutoff);

        $filename = 'legacy-payments-classified.csv';

        return response()->streamDownload(function () use ($result): void {
            $out = fopen('php://output', 'w');
            $headers = [
                'member_email',
                'member_number',
                'payment_date',
                'amount',
                'payment_type',
                'suggested_loan_number',
                'period',
                'notes',
            ];
            fputcsv($out, $headers);
            foreach ($result['rows'] as $row) {
                fputcsv($out, [
                    $row['member_email'],
                    $row['member_number'],
                    $row['payment_date'],
                    $row['amount'],
                    $row['payment_type'],
                    $row['suggested_loan_number'],
                    $row['period'],
                    $row['notes'],
                ]);
            }
            fclose($out);
        }, $filename, [
            'Content-Type' => 'text/csv',
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
