<?php

use App\Support\Lang;
use Filament\Infolists\Components\TextEntry;
use Filament\Support\Enums\TextSize;
use Filament\Tables\Columns\Column;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;

it('defaults table columns to toggleable, sortable, searchable, translateLabel, and wrapped headers', function () {
    $text = TextColumn::make('name');
    $icon = IconColumn::make('is_active');

    $shouldTranslate = function (Column $column): bool {
        $prop = new ReflectionProperty($column, 'shouldTranslateLabel');
        $prop->setAccessible(true);

        return (bool) $prop->getValue($column);
    };

    expect($text->isSearchable())->toBeTrue()
        ->and($text->isSortable())->toBeTrue()
        ->and($text->isToggleable())->toBeTrue()
        ->and($shouldTranslate($text))->toBeTrue()
        ->and($text->canWrap())->toBeTrue()
        ->and($text->getSize(null))->toBe(TextSize::ExtraSmall)
        ->and($text->canHeaderWrap())->toBeTrue()
        ->and($icon->isSearchable())->toBeTrue()
        ->and($icon->isSortable())->toBeTrue()
        ->and($icon->isToggleable())->toBeTrue()
        ->and($shouldTranslate($icon))->toBeTrue()
        ->and($icon->canHeaderWrap())->toBeTrue();
});

it('title-cases resolved table header labels', function () {
    $column = TextColumn::make('example_field')->label('lower case header');

    $label = $column->getLabel();

    expect(is_object($label) ? (string) $label : $label)->toBe('Lower Case Header');
});

it('formats ui labels with title case', function () {
    expect(Lang::formatUiLabel('member cash balance'))
        ->toBe('Member Cash Balance')
        ->and(Lang::formatUiLabel(''))->toBe('');
});

it('defaults text infolist entries to wrap and compact size', function () {
    $entry = TextEntry::make('notes');

    expect($entry->canWrap())->toBeTrue()
        ->and($entry->getSize(null))->toBe(TextSize::ExtraSmall);
});
