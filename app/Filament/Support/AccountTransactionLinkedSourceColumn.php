<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Transaction;
use Filament\Tables\Columns\TextColumn;

final class AccountTransactionLinkedSourceColumn
{
    public static function make(string $name = 'linked_source'): TextColumn
    {
        return TextColumn::make($name)
            ->label(__('Linked source'))
            ->state(fn (Transaction $record): string => $record->linkedSourceLabel())
            ->badge()
            ->color(fn (Transaction $record): string => $record->hasLinkedReference() ? 'gray' : 'warning')
            ->wrap()
            ->searchable(false)
            ->sortable(false)
            ->toggleable();
    }
}
