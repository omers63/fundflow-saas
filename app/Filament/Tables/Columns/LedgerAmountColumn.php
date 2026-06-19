<?php

namespace App\Filament\Tables\Columns;

use App\Filament\Support\MoneyDisplay;
use App\Filament\Tables\Columns\Summarizers\SignedLedgerSum;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Transaction;
use Filament\Facades\Filament;
use Illuminate\Contracts\Support\Htmlable;

class LedgerAmountColumn extends TextColumn
{
    protected function setUp(): void
    {
        $currency = fn (): string => Setting::get('general', 'currency', 'USD');

        $this
            ->sortable()
            ->formatStateUsing(function ($state, Transaction $record) use ($currency): Htmlable|string|null {
                if (blank($state) && blank($record->amount)) {
                    return null;
                }

                $signed = $record->getSignedAmount();

                if (Filament::getCurrentPanel()?->getId() === 'member') {
                    return MoneyDisplay::html($signed, $currency(), signed: true);
                }

                return MoneyDisplay::format($signed, $currency());
            })
            ->badge()
            ->color(fn ($state, Transaction $record): string => MoneyDisplay::color($record->getSignedAmount()));

        if (Filament::getCurrentPanel()?->getId() === 'member') {
            $this->html();
        }

        $columnName = $this->getName();

        $signedNetSum = SignedLedgerSum::make()
            ->label(fn (): string => str($columnName)->replace('_', ' ')->headline()->toString())
            ->translateLabel()
            ->formatStateUsing(function ($state) use ($currency): ?string {
                if ($state === null) {
                    return null;
                }

                if (Filament::getCurrentPanel()?->getId() === 'member') {
                    return MoneyDisplay::tableSummaryHtml((float) $state, $currency(), signed: true);
                }

                return MoneyDisplay::format((float) $state, $currency());
            });

        if (Filament::getCurrentPanel()?->getId() === 'member') {
            $signedNetSum->html();
        }

        $this->summarize([$signedNetSum]);
    }
}
