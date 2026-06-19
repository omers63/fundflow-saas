<?php

namespace App\Filament\Tables\Columns;

use App\Filament\Support\MoneyDisplay;
use App\Filament\Tables\Concerns\CapitalizesTableColumnHeaderLabel;
use App\Models\Tenant\Setting;
use BackedEnum;
use Closure;
use Filament\Facades\Filament;
use Filament\Tables\Columns\TextColumn as FilamentTextColumn;

class TextColumn extends FilamentTextColumn
{
    use CapitalizesTableColumnHeaderLabel;

    public function money(string|BackedEnum|Closure|null $currency = null, int|Closure $divideBy = 0, string|BackedEnum|Closure|null $locale = null, int|Closure|null $decimalPlaces = null): static
    {
        parent::money($currency, $divideBy, $locale, $decimalPlaces);

        $this->formatStateUsing(function (FilamentTextColumn $column, $state) use ($currency, $divideBy, $locale): ?string {
            if (blank($state)) {
                return null;
            }

            if (! is_numeric($state)) {
                return (string) $state;
            }

            $amount = (float) $state;

            if ($divideByValue = $column->evaluate($divideBy)) {
                $amount /= $divideByValue;
            }

            $currencyCode = $column->evaluate($currency)
                ?? $column->getTable()?->getDefaultCurrency()
                ?? Setting::get('general', 'currency', 'USD');

            if ($currencyCode instanceof BackedEnum) {
                $currencyCode = (string) $currencyCode->value;
            }

            $localeCode = $column->evaluate($locale)
                ?? $column->getTable()?->getDefaultNumberLocale()
                ?? config('app.locale');

            if ($localeCode instanceof BackedEnum) {
                $localeCode = (string) $localeCode->value;
            }

            if (Filament::getCurrentPanel()?->getId() === 'member') {
                return MoneyDisplay::html($amount, (string) $currencyCode);
            }

            return MoneyDisplay::format($amount, (string) $currencyCode, (string) $localeCode);
        });

        $column = $this;

        if (Filament::getCurrentPanel()?->getId() === 'member') {
            $column = $column->html();
        }

        return $column
            ->badge()
            ->color(fn ($state): string => MoneyDisplay::color($state));
    }
}
