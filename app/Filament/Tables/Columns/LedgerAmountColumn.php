<?php

namespace App\Filament\Tables\Columns;

use App\Filament\Support\MoneyDisplay;
use App\Filament\Tables\Columns\Summarizers\SignedLedgerSum;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use App\Support\Lang;

class LedgerAmountColumn extends TextColumn
{
    protected function setUp(): void
    {
        $currency = fn (): string => Setting::get('general', 'currency', 'USD');

        $this
            ->sortable()
            ->formatStateUsing(function ($state, Transaction $record) use ($currency): ?string {
                if (blank($state) && blank($record->amount)) {
                    return null;
                }

                return MoneyDisplay::format($record->getSignedAmount(), $currency());
            })
            ->badge()
            ->color(fn ($state, Transaction $record): string => MoneyDisplay::color($record->getSignedAmount()));

        $label = Lang::formatUiLabel(str_replace('_', ' ', $this->getName()));

        $signedNetSum = SignedLedgerSum::make()
            ->label($label)
            ->formatStateUsing(fn ($state): ?string => MoneyDisplay::format((float) $state, $currency()));

        $this->summarize([$signedNetSum]);
    }
}
