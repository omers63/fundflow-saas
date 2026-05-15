<?php

namespace App\Filament\Support;

use App\Filament\Tables\Columns\LedgerAmountColumn;
use Filament\Tables\Columns\TextColumn;

final class AccountTransactionAmountColumn
{
    /**
     * Signed ledger amount column (debits negative, credits positive) with correct footer sums.
     */
    public static function make(string $name = 'amount'): TextColumn
    {
        return LedgerAmountColumn::make($name);
    }
}
