<?php

declare(strict_types=1);

use App\Support\Utf8CsvStream;

test('utf8 csv stream writes bom and exposes download headers', function () {
    expect(Utf8CsvStream::CONTENT_TYPE)->toBe('text/csv; charset=UTF-8')
        ->and(Utf8CsvStream::downloadHeaders())->toBe(['Content-Type' => 'text/csv; charset=UTF-8']);

    ob_start();
    $handle = Utf8CsvStream::open();
    fputcsv($handle, ['member', 'amount']);
    fputcsv($handle, ['Sample', '100']);
    fclose($handle);
    $csv = (string) ob_get_clean();

    expect($csv)->toStartWith("\xEF\xBB\xBF")
        ->and($csv)->toContain('Sample');
});
