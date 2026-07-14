<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\Loan;
use Closure;
use Filament\Tables\Columns\TextColumn;
use Illuminate\Database\Eloquent\Builder;

final class LoanOutstandingColumn
{
    public static function make(string $currency): TextColumn
    {
        return self::fromLoanResolver(
            fn (Loan $record): Loan => $record,
            $currency,
            sortQuery: fn (Builder $query, string $direction): Builder => $query->orderByOutstanding($direction),
        );
    }

    public static function fromLoanResolver(
        callable $resolveLoan,
        string $currency,
        string $name = 'outstanding',
        ?Closure $sortQuery = null,
    ): TextColumn {
        $column = TextColumn::make($name)
            ->label(__('Loan outstanding'))
            ->state($resolveLoan)
            ->formatStateUsing(function (mixed $state) use ($currency): string {
                if (! $state instanceof Loan) {
                    return '—';
                }

                return view('components.loan-outstanding-cell', [
                    'loan' => $state,
                    'currency' => $currency,
                ])->render();
            })
            ->html()
            ->placeholder('—')
            ->extraCellAttributes(['class' => 'ff-loan-outstanding-column'])
            ->searchable(false);

        if ($sortQuery !== null) {
            $column->sortable(query: $sortQuery);
        }

        return $column;
    }
}
