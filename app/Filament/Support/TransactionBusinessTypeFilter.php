<?php

declare(strict_types=1);

namespace App\Filament\Support;

use App\Support\TransactionBusinessTypeCatalog;
use Filament\Tables\Filters\SelectFilter;
use Illuminate\Database\Eloquent\Builder;

final class TransactionBusinessTypeFilter
{
    public static function make(string $name = 'business_type'): SelectFilter
    {
        return SelectFilter::make($name)
            ->label(__('Transaction type'))
            ->options(TransactionBusinessTypeCatalog::options())
            ->query(function (Builder $query, array $data): Builder {
                return TransactionBusinessTypeCatalog::applyFilter(
                    $query,
                    $data['value'] ?? null,
                );
            });
    }
}
