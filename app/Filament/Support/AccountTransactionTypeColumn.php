<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Transaction;
use Filament\Tables\Columns\TextColumn;

final class AccountTransactionTypeColumn
{
    public static function make(string $name = 'type'): TextColumn
    {
        return TextColumn::make($name)
            ->formatStateUsing(fn (?string $state, ?Transaction $record = null): string => Transaction::typeLabel($record?->type ?? $state))
            ->badge()
            ->color(fn (?string $state, ?Transaction $record = null): string => match ($record?->type ?? $state) {
                'credit' => 'success',
                'debit' => 'danger',
                default => 'gray',
            });
    }
}
