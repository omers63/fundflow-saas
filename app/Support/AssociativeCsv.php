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
        if (!is_readable($absolutePath)) {
            throw new InvalidArgumentException(__('Cannot read the uploaded file.'));
        }

        $content = file_get_contents($absolutePath);

        if ($content === false) {
            throw new InvalidArgumentException(__('Cannot read the uploaded file.'));
        }

        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content) ?? $content;

        $lines = preg_split('/\r\n|\r|\n/', $content);
        $lines = array_values(array_filter($lines, fn($line) => trim((string) $line) !== ''));

        if (count($lines) < 2) {
            return [];
        }

        $headerLine = array_shift($lines);
        $headers = str_getcsv((string) $headerLine);
        $headers = array_map(
            fn($header) => strtolower(trim(str_replace(' ', '_', (string) $header))),
            $headers,
        );

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
        $handle = fopen($absolutePath, 'w');

        if ($handle === false) {
            throw new InvalidArgumentException(__('Cannot write CSV file.'));
        }

        fputcsv($handle, $headers);

        foreach ($rows as $row) {
            $line = [];

            foreach ($headers as $header) {
                $line[] = is_array($row) ? ($row[$header] ?? '') : '';
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
        if (!is_readable($absolutePath)) {
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

        $headers = str_getcsv($headerLine);

        return array_values(array_filter(array_map(
            fn($header) => strtolower(trim(str_replace(' ', '_', (string) $header))),
            $headers,
        )));
    }
}
