<?php

declare(strict_types=1);

namespace App\Support;

final class IbanValidator
{
    public static function isValid(?string $iban): bool
    {
        if ($iban === null) {
            return false;
        }

        $normalized = self::normalize($iban);

        if ($normalized === null || strlen($normalized) < 15 || strlen($normalized) > 34) {
            return false;
        }

        if (! preg_match('/^[A-Z]{2}[0-9]{2}[A-Z0-9]+$/', $normalized)) {
            return false;
        }

        $rearranged = substr($normalized, 4).substr($normalized, 0, 4);
        $numeric = '';

        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string) (ord($char) - 55) : $char;
        }

        return self::mod97($numeric) === 1;
    }

    public static function normalize(?string $iban): ?string
    {
        if ($iban === null) {
            return null;
        }

        $normalized = strtoupper(preg_replace('/\s+/', '', $iban) ?? '');

        return $normalized === '' ? null : $normalized;
    }

    private static function mod97(string $numeric): int
    {
        $checksum = 0;
        $length = strlen($numeric);

        for ($i = 0; $i < $length; $i++) {
            $checksum = (($checksum * 10) + (int) $numeric[$i]) % 97;
        }

        return $checksum;
    }
}
