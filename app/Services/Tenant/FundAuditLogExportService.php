<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\FundAuditLog;
use App\Support\BusinessDay;
use App\Support\Utf8CsvStream;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class FundAuditLogExportService
{
    /**
     * @return list<string>
     */
    public static function csvHeaders(): array
    {
        return [
            'id',
            'occurred_at',
            'event_type',
            'domain',
            'member_id',
            'operator_id',
            'subject_type',
            'subject_id',
            'payload_json',
        ];
    }

    public function downloadCsv(?Carbon $from = null, ?Carbon $until = null): StreamedResponse
    {
        $filename = 'fund-audit-log-'.BusinessDay::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($from, $until): void {
            $handle = Utf8CsvStream::open();
            fputcsv($handle, self::csvHeaders());

            $this->query($from, $until)
                ->each(function (FundAuditLog $log) use ($handle): void {
                    fputcsv($handle, [
                        $log->id,
                        $log->occurred_at?->toDateTimeString(),
                        $log->event_type,
                        $log->domain,
                        $log->member_id,
                        $log->operator_id,
                        $log->subject_type,
                        $log->subject_id,
                        json_encode($log->payload ?? [], JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE),
                    ]);
                });

            fclose($handle);
        }, $filename, [
            ...Utf8CsvStream::downloadHeaders(),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }

    /**
     * @return Builder<FundAuditLog>
     */
    private function query(?Carbon $from, ?Carbon $until): Builder
    {
        $query = FundAuditLog::query()->orderByDesc('occurred_at');

        if ($from !== null) {
            $query->where('occurred_at', '>=', $from->copy()->startOfDay());
        }

        if ($until !== null) {
            $query->where('occurred_at', '<=', $until->copy()->endOfDay());
        }

        return $query;
    }
}
