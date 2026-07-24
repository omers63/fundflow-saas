<?php

declare(strict_types=1);

namespace App\Services\Tenant;

use App\Models\Tenant\NotificationLog;
use App\Support\BusinessDay;
use App\Support\Utf8CsvStream;
use Illuminate\Database\Eloquent\Builder;
use Symfony\Component\HttpFoundation\StreamedResponse;

final class NotificationLogExportService
{
    /**
     * @return list<string>
     */
    public static function csvHeaders(): array
    {
        return [
            'id',
            'sent_at',
            'user_id',
            'recipient_name',
            'recipient_email',
            'channel',
            'subject',
            'body',
            'status',
            'error_message',
            'created_at',
        ];
    }

    public function downloadCsv(): StreamedResponse
    {
        return $this->downloadCsvFromQuery(
            NotificationLog::query()->with('user')->orderByDesc('sent_at')->orderByDesc('id')
        );
    }

    /**
     * @param  Builder<NotificationLog>  $query
     */
    public function downloadCsvFromQuery(Builder $query): StreamedResponse
    {
        $filename = 'notification-log-'.BusinessDay::now()->format('Y-m-d').'.csv';

        return response()->streamDownload(function () use ($query): void {
            $handle = Utf8CsvStream::open();
            fputcsv($handle, self::csvHeaders());

            (clone $query)
                ->with('user')
                ->each(function (NotificationLog $log) use ($handle): void {
                    fputcsv($handle, [
                        $log->id,
                        $log->sent_at?->toDateTimeString(),
                        $log->user_id,
                        $log->user?->name,
                        $log->user?->email,
                        $log->channel,
                        $log->subject,
                        $log->body,
                        $log->status,
                        $log->error_message,
                        $log->created_at?->toDateTimeString(),
                    ]);
                });

            fclose($handle);
        }, $filename, [
            ...Utf8CsvStream::downloadHeaders(),
            'Cache-Control' => 'no-store, no-cache, must-revalidate, max-age=0',
        ]);
    }
}
