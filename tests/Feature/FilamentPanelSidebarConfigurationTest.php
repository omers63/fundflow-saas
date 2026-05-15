<?php

use Filament\Facades\Filament;

test('filament panels enable desktop sidebar collapse', function (string $panelId) {
    $panel = Filament::getPanel($panelId);

    expect($panel->isSidebarCollapsibleOnDesktop())->toBeTrue()
        ->and($panel->isSidebarFullyCollapsibleOnDesktop())->toBeTrue();
})->with([
    'central admin' => 'admin',
    'tenant fund admin' => 'tenant',
    'member portal' => 'member',
]);
