<?php

declare(strict_types=1);

namespace App\Filament\Support;

use Filament\Forms\Components\Select;
use Filament\Tables\Filters\SelectFilter;

/**
 * Consistent searchable member combobox / filter configuration.
 */
final class MemberSelect
{
    public static function make(string $name = 'member_id'): Select
    {
        return self::configure(Select::make($name)->label(__('Member')));
    }

    public static function configure(Select $select, bool $activeOnly = true): Select
    {
        return $select
            ->searchable()
            ->preload()
            ->native(false)
            ->optionsLimit(75)
            ->options(fn (): array => MemberSelectOptions::options(activeOnly: $activeOnly, limit: 75))
            ->getSearchResultsUsing(
                fn (string $search): array => MemberSelectOptions::search($search, $activeOnly),
            )
            ->getOptionLabelUsing(
                fn (mixed $value): ?string => MemberSelectOptions::labelForId(
                    is_numeric($value) ? (int) $value : (is_string($value) ? $value : null),
                ),
            );
    }

    public static function filter(string $name = 'member_id'): SelectFilter
    {
        return self::configureFilter(SelectFilter::make($name)->label(__('Member')));
    }

    public static function configureFilter(SelectFilter $filter, bool $activeOnly = false): SelectFilter
    {
        return $filter
            ->searchable()
            ->preload()
            ->optionsLimit(75)
            ->options(fn (): array => MemberSelectOptions::options(activeOnly: $activeOnly, limit: 75))
            ->getSearchResultsUsing(
                fn (string $search): array => MemberSelectOptions::search($search, $activeOnly),
            )
            ->getOptionLabelUsing(
                fn (mixed $value): ?string => MemberSelectOptions::labelForId(
                    is_numeric($value) ? (int) $value : (is_string($value) ? $value : null),
                ),
            );
    }
}
