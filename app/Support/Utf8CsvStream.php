<?php

declare(strict_types=1);

namespace App\Support;

final class Utf8CsvStream
{
    public const CONTENT_TYPE = 'text/csv; charset=UTF-8';

    /**
     * @return array<string, string>
     */
    public static function downloadHeaders(): array
    {
        return ['Content-Type' => self::CONTENT_TYPE];
    }

    /**
     * @return resource
     */
    public static function open()
    {
        return self::openFile('php://output');
    }

    /**
     * @return resource
     */
    public static function openFile(string $absolutePath)
    {
        $handle = fopen($absolutePath, 'w');

        if ($handle === false) {
            throw new \RuntimeException('Unable to open CSV output stream.');
        }

        fwrite($handle, "\xEF\xBB\xBF");

        return $handle;
    }
}
