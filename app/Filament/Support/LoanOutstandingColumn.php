<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Loan;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

final class LoanOutstandingColumn
{
    public static function make(string $currency): TextColumn
    {
        return TextColumn::make('outstanding')
            ->label(__('Outstanding'))
            ->state(fn (Loan $record): Loan => $record)
            ->formatStateUsing(fn (Loan $record): string => view('components.loan-outstanding-cell', [
                'loan' => $record,
                'currency' => $currency,
            ])->render())
            ->html()
            ->extraCellAttributes(['class' => 'ff-loan-outstanding-column'])
            ->sortable(query: fn (Builder $query, string $direction): Builder => $query->orderByOutstanding($direction));
    }
}
