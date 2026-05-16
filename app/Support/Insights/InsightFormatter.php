<?php

declare(strict_types=1);

namespace App\Support\Insights;

use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\Setting;

final class InsightFormatter
{
    public static function currency(): string
    {
        return (string) Setting::get('general', 'currency', 'USD');
    }

    public static function money(float $amount): string
    {
        return MoneyDisplay::format($amount, self::currency()) ?? '—';
    }

    public static function compactAmount(float $amount): string
    {
        $abs = abs($amount);

        if ($abs >= 1_000_000) {
            return round($abs / 1_000_000, 1).'M';
        }

        if ($abs >= 1_000) {
            return round($abs / 1_000, 1).'k';
        }

        return number_format($abs, $abs >= 100 ? 0 : 2);
    }

    /**
     * @return array{display: string, full: string, is_negative: bool}
     */
    public static function moneyKpi(float $amount): array
    {
        return [
            'display' => self::compactAmount($amount),
            'full' => self::money($amount),
            'is_negative' => $amount < 0,
        ];
    }

    public static function percent(?float $value, int $decimals = 1): string
    {
        return $value === null ? '—' : number_format($value, $decimals).'%';
    }
}
