<?php

use App\Filament\Support\DateColumnRangeFilter;
use Filament\Tables\Filters\Filter;

it('builds a date column range filter', function () {
    $filter = DateColumnRangeFilter::make('posted_at', 'Posted on');

    expect($filter)->toBeInstanceOf(Filter::class)
        ->and($filter->getName())->toBe('date_range_posted_at');
});
