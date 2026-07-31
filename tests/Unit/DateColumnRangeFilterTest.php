<?php

declare(strict_types=1);

use App\Filament\Support\DateColumnRangeFilter;
use Filament\Forms\Components\DatePicker;
use Filament\Schemas\Components\Fieldset;
use Filament\Tables\Filters\Filter;

it('builds a date column range filter', function () {
    $filter = DateColumnRangeFilter::make('posted_at', 'Posted on');

    expect($filter)->toBeInstanceOf(Filter::class)
        ->and($filter->getName())->toBe('date_range_posted_at');
});

it('wraps from and until fields in a fieldset titled with the column label', function () {
    $filter = DateColumnRangeFilter::make('period', 'Contribution period');
    $components = $filter->getSchemaComponents();

    expect($components)->toHaveCount(1)
        ->and($components[0])->toBeInstanceOf(Fieldset::class);

    // Fieldset label is translateLabel()'d from the column name.
    expect($components[0]->getLabel())->toBe(__('Contribution period'));

    $pickers = $components[0]->getDefaultChildComponents();

    expect($pickers)->toHaveCount(2)
        ->and($pickers[0])->toBeInstanceOf(DatePicker::class)
        ->and($pickers[0]->getName())->toBe('from')
        ->and($pickers[0]->getLabel())->toBe(__('From'))
        ->and($pickers[1])->toBeInstanceOf(DatePicker::class)
        ->and($pickers[1]->getName())->toBe('until')
        ->and($pickers[1]->getLabel())->toBe(__('Until'));
});

it('builds last ledger activity filter with the same fieldset pattern', function () {
    $filter = DateColumnRangeFilter::forLastLedgerActivity('Last activity');
    $components = $filter->getSchemaComponents();

    expect($filter->getName())->toBe('date_range_last_activity_at')
        ->and($components[0])->toBeInstanceOf(Fieldset::class)
        ->and($components[0]->getLabel())->toBe(__('Last activity'));
});
