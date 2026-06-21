<?php

namespace App\Filament\Tables\Columns;

use App\Filament\Support\MoneyDisplay;
use App\Filament\Tables\Columns\Summarizers\SignedLedgerSum;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;

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

                $signed = $record->getSignedAmount();

                return MoneyDisplay::html($signed, $currency(), signed: true)?->toHtml();
            })
            ->badge()
            ->color(fn ($state, Transaction $record): string => MoneyDisplay::color($record->getSignedAmount()))
            ->html();

        $columnName = $this->getName();

        $signedNetSum = SignedLedgerSum::make()
            ->label(fn (): string => str($columnName)->replace('_', ' ')->headline()->toString())
            ->translateLabel()
            ->formatStateUsing(function ($state) use ($currency): ?string {
                if ($state === null) {
                    return null;
                }

                return MoneyDisplay::tableSummaryHtml((float) $state, $currency(), signed: true);
            })
            ->html();

        $this->summarize([$signedNetSum]);
    }
}
