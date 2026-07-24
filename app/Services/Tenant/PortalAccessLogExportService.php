<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\PortalAccessLog;
use App\Support\BusinessDay;
use App\Support\Utf8CsvStream;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class PortalAccessLogExportService
{
    /**
     * @return list<string>
     */
    public static function csvHeaders(): array
    {
        return [
            'id',
            'accessed_at',
            'member_name',
            'member_id',
            'member_number',
            'user_id',
            'login_email',
            'panel',
            'ip_address',
            'user_agent',
        ];
    }

    public function downloadCsv(): StreamedResponse
    {
        return $this->downloadCsvFromQuery(
            PortalAccessLog::query()
                ->with(['member', 'user'])
                ->orderByDesc('accessed_at')
                ->orderByDesc('id')
        );
    }

    /**
     * @param  Builder<PortalAccessLog>  $query
     */
    public function downloadCsvFromQuery(Builder $query): StreamedResponse
    {
        $filename = 'portal-access-log-'.BusinessDay::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = Utf8CsvStream::open();
            fputcsv($handle, self::csvHeaders());

            (clone $query)
                ->with(['member', 'user'])
                ->each(function (PortalAccessLog $log) use ($handle): void {
                    fputcsv($handle, [
                        $log->id,
                        $log->accessed_at?->toDateTimeString(),
                        $log->displayName(),
                        $log->member_id,
                        $log->member?->member_number,
                        $log->user_id,
                        $log->user?->email,
                        $log->panel,
                        $log->ip_address,
                        $log->user_agent,
                    ]);
                });

            fclose($handle);
        }, $filename, [
            ...Utf8CsvStream::downloadHeaders(),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
