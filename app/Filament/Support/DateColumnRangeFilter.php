<?php

namespace App\Filament\Support;

use Carbon\Carbon;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Fieldset;
use Filament\Tables\Filters\Filter;
use Filament\Tables\Filters\Indicator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;

final class DateColumnRangeFilter
{
    /**
     * Inclusive date range filter for latest ledger activity on an account.
     */
    public static function forLastLedgerActivity(?string $label = null): Filter
    {
        $filterName = 'date_range_last_activity_at';
        $label ??= __('Last activity');

        return self::configure(
            Filter::make($filterName),
            $label,
            function (Builder $query, array $data): Builder {
                return $query
                    ->when(
                        filled($data['from'] ?? null),
                        fn (Builder $query): Builder => $query->whereLastActivityDateOnOrAfter((string) $data['from']),
                    )
                    ->when(
                        filled($data['until'] ?? null),
                        fn (Builder $query): Builder => $query->whereLastActivityDateOnOrBefore((string) $data['until']),
                    );
            },
        );
    }

    /**
     * Inclusive date range filter for a column on the table’s root model (not dotted relationship paths).
     */
    public static function make(string $column, ?string $label = null): Filter
    {
        $filterName = 'date_range_'.str_replace(['.', '-'], '_', $column);
        $label ??= Str::headline(str_replace('_', ' ', Str::afterLast($column, '.')));

        return self::configure(
            Filter::make($filterName),
            $label,
            function (Builder $query, array $data) use ($column): Builder {
                $qualified = $query->qualifyColumn($column);

                return $query
                    ->when(
                        filled($data['from'] ?? null),
                        fn (Builder $query): Builder => $query->whereDate($qualified, '>=', $data['from']),
                    )
                    ->when(
                        filled($data['until'] ?? null),
                        fn (Builder $query): Builder => $query->whereDate($qualified, '<=', $data['until']),
                    );
            },
        );
    }

    /**
     * Schema filters do not render the filter label — only schema fields — so wrap From/Until
     * in a fieldset titled with the column name (avoids two identical unlabeled date pairs).
     *
     * @param  callable(Builder, array<string, mixed>): Builder  $query
     */
    private static function configure(Filter $filter, string $label, callable $query): Filter
    {
        return $filter
            ->label($label)
            ->schema([
                Fieldset::make($label)
                    ->schema([
                        DatePicker::make('from')
                            ->label(__('From')),
                        DatePicker::make('until')
                            ->label(__('Until')),
                    ])
                    ->columns(2),
            ])
            ->query($query)
            ->indicateUsing(function (array $data) use ($label): array {
                $indicators = [];

                if (filled($data['from'] ?? null)) {
                    $indicators[] = Indicator::make(__(':label from :date', [
                        'label' => __($label),
                        'date' => Carbon::parse($data['from'])->toFormattedDateString(),
                    ]))
                        ->removeField('from');
                }

                if (filled($data['until'] ?? null)) {
                    $indicators[] = Indicator::make(__(':label until :date', [
                        'label' => __($label),
                        'date' => Carbon::parse($data['until'])->toFormattedDateString(),
                    ]))
                        ->removeField('until');
                }

                return $indicators;
            });
    }
}
