<?php

declare(strict_types=1);

use App\Filament\Support\TableRecordActionGroups;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\EditAction;
use Filament\Actions\ViewAction;
use Filament\Tables\Contracts\HasTable;
use Filament\Tables\Table;
use Tests\TestCase;

uses(TestCase::class);

test('is single primary action when only view or edit action is present', function () {
    expect(TableRecordActionGroups::isSinglePrimaryAction([ViewAction::make()]))->toBeTrue()
        ->and(TableRecordActionGroups::isSinglePrimaryAction([EditAction::make()]))->toBeTrue()
        ->and(TableRecordActionGroups::isSinglePrimaryAction([
            ViewAction::make(),
            Action::make('edit'),
        ]))->toBeFalse()
        ->and(TableRecordActionGroups::isSinglePrimaryAction([]))->toBeFalse();
});

test('flatten unwraps action groups', function () {
    $flat = TableRecordActionGroups::flatten([
        ActionGroup::make([
            Action::make('a'),
            Action::make('b'),
        ]),
    ]);

    expect($flat)->toHaveCount(2);
});

test('table record action groups wrap loose actions in a single labeled group', function () {
    $actions = TableRecordActionGroups::wrap([
        Action::make('edit')->label('Edit'),
    ]);

    expect($actions)->toHaveCount(1)
        ->and($actions[0])->toBeInstanceOf(ActionGroup::class)
        ->and($actions[0]->getLabel())->toBe(__('Actions'));
});

test('table record action groups leave all-action-group lists unchanged', function () {
    $groups = [
        ActionGroup::make([Action::make('a')->label('A')])->label('G1'),
        ActionGroup::make([Action::make('b')->label('B')])->label('G2'),
    ];

    expect(TableRecordActionGroups::wrap($groups))->toBe($groups);
});

test('normalize removes actions column for a single edit action', function () {
    $livewire = Mockery::mock(HasTable::class);
    $table = Table::make($livewire)
        ->recordActions(TableRecordActionGroups::wrap([
            EditAction::make(),
        ]));

    $normalized = TableRecordActionGroups::normalizeSinglePrimaryActionRowClick($table);

    expect($normalized->getRecordActions())->toBe([])
        ->and($normalized->getRecordAction([]))->toBe(EditAction::getDefaultName());
});

test('normalize keeps record url and clears actions for a single view action', function () {
    $livewire = Mockery::mock(HasTable::class);
    $table = Table::make($livewire)
        ->recordUrl(fn (): string => '/example')
        ->recordActions(TableRecordActionGroups::wrap([
            ViewAction::make(),
        ]));

    $normalized = TableRecordActionGroups::normalizeSinglePrimaryActionRowClick($table);

    expect($normalized->getRecordActions())->toBe([])
        ->and($normalized->getRecordUrl([]))->toBe('/example');
});

test('normalize leaves multiple row actions unchanged', function () {
    $livewire = Mockery::mock(HasTable::class);
    $table = Table::make($livewire)
        ->recordActions(TableRecordActionGroups::wrap([
            ViewAction::make(),
            EditAction::make(),
        ]));

    $normalized = TableRecordActionGroups::normalizeSinglePrimaryActionRowClick($table);

    expect($normalized->getRecordActions())->toHaveCount(1)
        ->and($normalized->getRecordActions()[0])->toBeInstanceOf(ActionGroup::class);
});

test('apply configures row click for a single view action without record url', function () {
    $livewire = Mockery::mock(HasTable::class);
    $table = Table::make($livewire);

    $configured = TableRecordActionGroups::apply($table, [ViewAction::make()]);

    expect($configured->getRecordActions())->toBe([])
        ->and($configured->getRecordAction([]))->toBe(ViewAction::getDefaultName());
});
