<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Tables\Filters\TernaryFilter;
use Illuminate\Database\Eloquent\Builder;

final class AccountTransactionLinkedSourceFilter
{
    public static function make(string $name = 'has_linked_source'): TernaryFilter
    {
        return TernaryFilter::make($name)
            ->label(__('Linked source'))
            ->trueLabel(__('Has linked source'))
            ->falseLabel(__('Missing linked source'))
            ->queries(
                true: fn (Builder $query): Builder => $query
                    ->whereNotNull('reference_type')
                    ->whereNotNull('reference_id'),
                false: fn (Builder $query): Builder => $query->where(function (Builder $query): void {
                    $query->whereNull('reference_id')->orWhereNull('reference_type');
                }),
            );
    }
}
