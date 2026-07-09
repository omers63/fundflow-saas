<?php

declare(strict_types=1);

namespace App\Support;

use InvalidArgumentException;

final class AssociativeCsv
{
    /**
     * @return array<int, array<string, string>>
     */
    public static function read(string $absolutePath): array
    {
        if (! is_readable($absolutePath)) {
            throw new InvalidArgumentException(__('Cannot read the uploaded file.'));
        }

        $content = file_get_contents($absolutePath);

        if ($content === false) {
            throw new InvalidArgumentException(__('Cannot read the uploaded file.'));
        }

        $content = self::stripUtf8Bom($content);

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $lines = array_values(array_filter($lines, fn ($line) => trim((string) $line) !== ''));

        if (count($lines) < 2) {
            return [];
        }

        $headerLine = array_shift($lines);
        $headers = self::normalizeHeaders(str_getcsv((string) $headerLine));

        $rows = [];

        foreach ($lines as $line) {
            $cells = str_getcsv((string) $line);
            $assoc = [];

            foreach ($headers as $index => $key) {
                if ($key === '') {
                    continue;
                }

                $assoc[$key] = isset($cells[$index]) ? trim((string) $cells[$index]) : '';
            }

            $rows[] = $assoc;
        }

        return $rows;
    }

    /**
     * @param  list<string>  $headers
     */
    public static function write(string $absolutePath, array $headers, iterable $rows): void
    {
        $handle = Utf8CsvStream::openFile($absolutePath);

        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            $line = [];

            if (is_array($row) && array_is_list($row)) {
                foreach ($headers as $index => $header) {
                    $line[] = $row[$index] ?? '';
                }
            } else {
                foreach ($headers as $header) {
                    $line[] = is_array($row) ? ($row[$header] ?? '') : '';
                }
            }

            fputcsv($handle, $line);
        }

        fclose($handle);
    }

    /**
     * @return list<string>
     */
    public static function headers(string $absolutePath): array
    {
        if (! is_readable($absolutePath)) {
            return [];
        }

        $handle = fopen($absolutePath, 'r');

        if ($handle === false) {
            return [];
        }

        $headerLine = fgets($handle);
        fclose($handle);

        if ($headerLine === false) {
            return [];
        }

        return self::normalizeHeaders(str_getcsv(self::stripUtf8Bom($headerLine)));
    }

    /**
     * @param  list<string|null>  $headers
     * @return list<string>
     */
    private static function normalizeHeaders(array $headers): array
    {
        return array_values(array_filter(array_map(
            static fn (?string $header): string => self::normalizeHeader((string) $header),
            $headers,
        )));
    }

    private static function normalizeHeader(string $header): string
    {
        return strtolower(trim(str_replace(' ', '_', self::stripUtf8Bom($header))));
    }

    private static function stripUtf8Bom(string $value): string
    {
        $value = preg_replace('/^\xEF\xBB\xBF/', '', $value) ?? $value;

        return ltrim($value, "\u{FEFF}");
    }
}
