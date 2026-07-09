<?php

declare(strict_types=1);

namespace App\Services;

use App\Models\Tenant\Contribution;
use App\Support\BusinessDay;
use App\Support\Utf8CsvStream;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class ContributionExportService
{
    /**
     * @return list<string>
     */
    public static function csvHeaders(): array
    {
        return [
            'member_number',
            'member_name',
            'member_email',
            'period',
            'amount',
            'amount_due',
            'amount_collected',
            'status',
            'collection_status',
            'late_fee_amount',
            'posted_at',
            'paid_at',
            'payment_method',
            'reference_number',
            'notes',
        ];
    }

    public function downloadCsv(): StreamedResponse
    {
        $filename = 'contributions-'.BusinessDay::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function (): void {
            $handle = Utf8CsvStream::open();
            fputcsv($handle, self::csvHeaders());

            Contribution::query()
                ->with('member')
                ->orderByDesc('period')
                ->orderByDesc('id')
                ->each(function (Contribution $contribution) use ($handle): void {
                    fputcsv($handle, $this->csvRow($contribution));
                });

            fclose($handle);
        }, $filename, [
            ...Utf8CsvStream::downloadHeaders(),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /**
     * @return list<int|float|string|null>
     */
    private function csvRow(Contribution $contribution): array
    {
        return [
            $contribution->member?->member_number,
            $contribution->member?->name,
            $contribution->member?->email,
            $contribution->period?->format('Y-m-d'),
            $contribution->amount,
            $contribution->amount_due,
            $contribution->amount_collected,
            $contribution->status,
            $contribution->collection_status,
            $contribution->late_fee_amount,
            $contribution->posted_at?->toDateTimeString(),
            $contribution->paid_at?->toDateTimeString(),
            $contribution->payment_method,
            $contribution->reference_number,
            $contribution->notes,
        ];
    }
}
