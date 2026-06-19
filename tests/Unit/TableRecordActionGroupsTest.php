<?php

declare(strict_types=1);

use App\Filament\Support\TableRecordActionGroups;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\ViewAction;
use Tests\TestCase;

uses(TestCase::class);

test('is single view action when only view action is present', function () {
    expect(TableRecordActionGroups::isSingleViewAction([ViewAction::make()]))->toBeTrue()
        ->and(TableRecordActionGroups::isSingleViewAction([
            ViewAction::make(),
            Action::make('edit'),
        ]))->toBeFalse()
        ->and(TableRecordActionGroups::isSingleViewAction([]))->toBeFalse();
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
