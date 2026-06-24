<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use InvalidArgumentException;

final class LegacyMigrationDateParser
{
    /**
     * @param  list<string>|null  $formats
     */
    public static function parse(string $value, int $line, string $column = 'payment_date', ?array $formats = null): Carbon
    {
        return self::parseValue($value, $formats, $line, $column);
    }

    /**
     * @param  list<string>|null  $formats
     */
    public static function parseValue(string $value, ?array $formats = null, int $line = 0, string $column = 'payment_date'): Carbon
    {
        $normalized = self::stripTimeSuffix(trim($value));

        if ($normalized === '') {
            if ($line > 0) {
                throw new InvalidArgumentException("Row {$line}: {$column} is required.");
            }

            throw new InvalidArgumentException(__('Date is empty.'));
        }

        try {
            return ImportDateFormats::parse($normalized, $formats ?? LegacyMigrationDateFormatSettings::formats());
        } catch (InvalidArgumentException $exception) {
            if ($line > 0) {
                throw new InvalidArgumentException("Row {$line}: Invalid {$column} {$normalized}", 0, $exception);
            }

            throw $exception;
        }
    }

    private static function stripTimeSuffix(string $value): string
    {
        if (preg_match('/^(.+?)\s+\d{1,2}:\d{2}(?::\d{2})?$/', $value, $matches) === 1) {
            return trim($matches[1]);
        }

        return $value;
    }
}
