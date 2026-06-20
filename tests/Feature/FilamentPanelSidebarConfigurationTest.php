<?php

use App\Filament\Support\DatabaseNotificationsRefresh;
use Filament\Facades\Filament;

test('filament panels do not inject automatic livewire session reload scripts', function () {
    $providers = [
        app_path('Providers/Filament/MemberPanelProvider.php'),
        app_path('Providers/Filament/TenantPanelProvider.php'),
        app_path('Providers/Filament/AdminPanelProvider.php'),
    ];

    foreach ($providers as $provider) {
        $contents = file_get_contents($provider);

        expect($contents)
            ->not->toContain('livewire-session-recovery')
            ->not->toContain('location.reload');
    }

    expect(file_exists(resource_path('views/partials/livewire-session-recovery.blade.php')))->toBeFalse();
});

test('filament panels disable notification polling when echo broadcasting is configured', function (string $panelId) {
    expect(Filament::getPanel($panelId)->getDatabaseNotificationsPollingInterval())
        ->toBe(DatabaseNotificationsRefresh::panelPollingInterval());
})->with([
            'tenant fund admin' => 'tenant',
            'member portal' => 'member',
        ]);

test('filament panels enable desktop sidebar collapse', function (string $panelId) {
    $panel = Filament::getPanel($panelId);

    expect($panel->isSidebarCollapsibleOnDesktop())->toBeTrue()
        ->and($panel->isSidebarFullyCollapsibleOnDesktop())->toBeTrue();
})->with([
            'central admin' => 'admin',
            'tenant fund admin' => 'tenant',
            'member portal' => 'member',
        ]);
