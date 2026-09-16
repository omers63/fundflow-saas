<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\Tenant\Setting;

final class DepositOcrSettings
{
    public const GROUP = 'deposit_ocr';

    public static function autoAcceptEnabled(): bool
    {
        return (string) Setting::get(self::GROUP, 'auto_accept_enabled', '0') === '1';
    }

    public static function confidenceThreshold(): float
    {
        return (float) Setting::get(self::GROUP, 'confidence_threshold', 0.85);
    }

    public static function amountTolerance(): float
    {
        return (float) Setting::get(self::GROUP, 'amount_tolerance', 0.01);
    }

    public static function driver(): string
    {
        $configured = (string) Setting::get(self::GROUP, 'driver', '');

        if ($configured !== '') {
            return $configured;
        }

        return (string) config('ocr.driver', 'fake');
    }
}
