<?php

use Filament\Facades\Filament;

test('livewire session recovery script is present for filament panels', function () {
    $contents = file_get_contents(resource_path('views/partials/livewire-session-recovery.blade.php'));

    expect($contents)
        ->toContain('interceptRequest')
        ->toContain('419')
        ->toContain('401')
        ->toContain('location.reload');
});

test('filament panels enable desktop sidebar collapse', function (string $panelId) {
    $panel = Filament::getPanel($panelId);

    expect($panel->isSidebarCollapsibleOnDesktop())->toBeTrue()
        ->and($panel->isSidebarFullyCollapsibleOnDesktop())->toBeTrue();
})->with([
    'central admin' => 'admin',
    'tenant fund admin' => 'tenant',
    'member portal' => 'member',
]);
