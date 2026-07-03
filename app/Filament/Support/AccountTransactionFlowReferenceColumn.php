<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Models\Tenant\ExpenseDisbursement;
use App\Models\Tenant\InvestDisbursement;
use App\Models\Tenant\InvestReturn;
use App\Models\Tenant\Transaction;
use Filament\Tables\Columns\TextColumn;

final class AccountTransactionFlowReferenceColumn
{
    public static function invest(): TextColumn
    {
        return TextColumn::make('investment_id')
            ->label(__('Investment #'))
            ->state(fn (Transaction $record): ?int => self::referenceIdFor(
                $record,
                InvestDisbursement::class,
                InvestReturn::class,
            ))
            ->placeholder(__('—'))
            ->searchable(false)
            ->sortable(query: function ($query, string $direction): void {
                $query->orderBy('reference_id', $direction);
            });
    }

    public static function expense(): TextColumn
    {
        return TextColumn::make('expense_id')
            ->label(__('Expense #'))
            ->state(fn (Transaction $record): ?int => self::referenceIdFor(
                $record,
                ExpenseDisbursement::class,
            ))
            ->placeholder(__('—'))
            ->searchable(false)
            ->sortable(query: function ($query, string $direction): void {
                $query->orderBy('reference_id', $direction);
            });
    }

    /**
     * @param  class-string  ...$referenceTypes
     */
    private static function referenceIdFor(Transaction $record, string ...$referenceTypes): ?int
    {
        if ($record->reference_id === null || ! in_array($record->reference_type, $referenceTypes, true)) {
            return null;
        }

        return (int) $record->reference_id;
    }
}
