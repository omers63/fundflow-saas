<?php

namespace App\Filament\Support;

use App\Models\Tenant\Setting;
use Illuminate\Support\Number;

final class MoneyDisplay
{
    /**
     * Format like "SAR -50,000.00" (currency code, then signed amount).
     */
    public static function format(float|int|string|null $amount, ?string $currency = null, ?string $locale = null): ?string
    {
        if ($amount === null || $amount === '') {
            return null;
        }

        $numericAmount = (float) $amount;
        $currencyCode = $currency ?? Setting::get('general', 'currency', 'USD');
        $locale ??= config('app.locale');

        $formattedAmount = Number::format($numericAmount, 2, locale: $locale);

        return "{$currencyCode} {$formattedAmount}";
    }

    public static function color(float|int|string|null $amount): string
    {
        return ((float) $amount) < 0 ? 'danger' : 'success';
    }
}
