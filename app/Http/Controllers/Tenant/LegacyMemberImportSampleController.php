<?php

declare(strict_types=1);

namespace App\Http\Controllers\Tenant;

use App\Http\Controllers\Controller;
use App\Support\LegacyMigrationSampleCsv;
use App\Support\Utf8CsvStream;
use Symfony\Component\HttpFoundation\StreamedResponse;

class LegacyMemberImportSampleController extends Controller
{
    public function __invoke(): StreamedResponse
    {
        return $this->streamCsv(
            'legacy-members-import-sample.csv',
            LegacyMigrationSampleCsv::memberHeaders(),
            LegacyMigrationSampleCsv::memberRows(),
        );
    }

    /**
     * @param  list<string>  $headers
     * @param  list<list<string>>  $rows
     */
    private function streamCsv(string $filename, array $headers, array $rows): StreamedResponse
    {
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
