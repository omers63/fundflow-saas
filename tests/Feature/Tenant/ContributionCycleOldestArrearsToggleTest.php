<?php

declare(strict_types=1);

use App\Filament\Support\ContributionCycleHeaderActions;
use Filament\Forms\Components\Toggle;

test('run contribution cycle exposes collect oldest arrears first toggle', function () {
    $toggle = ContributionCycleHeaderActions::collectOldestArrearsFirstToggle();

    expect($toggle)->toBeInstanceOf(Toggle::class)
        ->and($toggle->getName())->toBe('collect_oldest_arrears_first')
        ->and($toggle->getDefaultState())->toBeTrue();
});

test('shared period form schema does not include oldest arrears toggle', function () {
    $names = collect(ContributionCycleHeaderActions::periodFormSchema())
        ->map(fn ($component) => $component->getName())
        ->all();

    expect($names)
        ->toContain('cycle')
        ->not->toContain('collect_oldest_arrears_first');
});
