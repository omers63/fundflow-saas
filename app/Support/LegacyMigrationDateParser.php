<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\Carbon;
use InvalidArgumentException;
use Throwable;

final class LegacyMigrationDateParser
{
    public static function parse(string $value, int $line, string $column = 'payment_date'): Carbon
    {
        $value = trim($value);

        if ($value === '') {
            throw new InvalidArgumentException("Row {$line}: {$column} is required.");
        }

        if (preg_match('/^(.+?)\s+\d{1,2}:\d{2}(?::\d{2})?$/', $value, $matches) === 1) {
            $value = trim($matches[1]);
        }

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1) {
            try {
                $parsed = Carbon::createFromFormat('Y-m-d', $value);

                if ($parsed instanceof Carbon) {
                    return $parsed->startOfDay();
                }
            } catch (Throwable) {
            }
        }

        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $value, $matches) === 1) {
            $parsed = self::parseSlashDate((int) $matches[1], (int) $matches[2], (int) $matches[3]);

            if ($parsed instanceof Carbon) {
                return $parsed->startOfDay();
            }
        } elseif (preg_match('/^\d{4}-\d{2}-\d{2}/', $value) !== 1 && preg_match('/^(\d{1,2})\D+(\d{1,2})\D+(\d{2,4})$/', $value, $matches) === 1) {
            $year = (int) $matches[3];

            if ($year < 100) {
                $year += $year >= 70 ? 1900 : 2000;
            }

            $parsed = self::parseSlashDate((int) $matches[1], (int) $matches[2], $year);

            if ($parsed instanceof Carbon) {
                return $parsed->startOfDay();
            }
        }

        foreach (['d-m-Y', 'Y-m-d'] as $format) {
            try {
                $parsed = Carbon::createFromFormat($format, $value);

                if ($parsed instanceof Carbon) {
                    return $parsed->startOfDay();
                }
            } catch (Throwable) {
            }
        }

        try {
            return Carbon::parse($value)->startOfDay();
        } catch (Throwable) {
            throw new InvalidArgumentException("Row {$line}: Invalid {$column} {$value}");
        }
    }

    private static function parseSlashDate(int $first, int $second, int $year): ?Carbon
    {
        if ($second > 12 && $first <= 12) {
            return self::tryFormat('m/d/Y', sprintf('%d/%d/%d', $first, $second, $year));
        }

        if ($first > 12 && $second <= 12) {
            return self::tryFormat('d/m/Y', sprintf('%d/%d/%d', $first, $second, $year));
        }

        return self::tryFormat('d/m/Y', sprintf('%d/%d/%d', $first, $second, $year))
            ?? self::tryFormat('m/d/Y', sprintf('%d/%d/%d', $first, $second, $year));
    }

    private static function tryFormat(string $format, string $value): ?Carbon
    {
        try {
            $parsed = Carbon::createFromFormat('!'.$format, $value);

            if (! $parsed instanceof Carbon) {
                return null;
            }

            $errors = Carbon::getLastErrors();

            if (is_array($errors) && (($errors['warning_count'] ?? 0) > 0 || ($errors['error_count'] ?? 0) > 0)) {
                return null;
            }

            return $parsed;
        } catch (Throwable) {
            return null;
        }
    }
}
