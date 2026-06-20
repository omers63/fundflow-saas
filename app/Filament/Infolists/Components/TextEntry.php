<?php

namespace App\Filament\Infolists\Components;

use App\Filament\Support\MoneyDisplay;
use App\Models\Tenant\Setting;
use BackedEnum;
use Closure;
use Filament\Infolists\Components\TextEntry as FilamentTextEntry;

class TextEntry extends FilamentTextEntry
{
    public function money(string|BackedEnum|Closure|null $currency = null, int|Closure $divideBy = 0, string|BackedEnum|Closure|null $locale = null, int|Closure|null $decimalPlaces = null): static
    {
        parent::money($currency, $divideBy, $locale, $decimalPlaces);

        $this->formatStateUsing(function (FilamentTextEntry $entry, $state) use ($currency, $divideBy): ?string {
            if (blank($state)) {
                return null;
            }

            if (! is_numeric($state)) {
                return (string) $state;
            }

            $amount = (float) $state;

            if ($divideByValue = $entry->evaluate($divideBy)) {
                $amount /= $divideByValue;
            }

            $currencyCode = $entry->evaluate($currency) ?? Setting::get('general', 'currency', 'USD');

            if ($currencyCode instanceof BackedEnum) {
                $currencyCode = (string) $currencyCode->value;
            }

            return MoneyDisplay::html($amount, (string) $currencyCode)?->toHtml();
        });

        return $this->html();
    }
}
