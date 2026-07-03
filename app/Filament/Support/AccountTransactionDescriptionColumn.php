<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Transaction;
use Filament\Tables\Columns\TextColumn;

final class AccountTransactionDescriptionColumn
{
    public static function make(string $name = 'description', bool $memberFacing = false): TextColumn
    {
        return TextColumn::make($name)
            ->searchable()
            ->wrap()
            ->state(fn (Transaction $record): string => $memberFacing
                ? $record->memberFacingDescription()
                : $record->displayDescription());
    }
}
