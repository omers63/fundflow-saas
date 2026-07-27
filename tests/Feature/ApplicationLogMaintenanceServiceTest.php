<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\SystemMaintenancePage;
use App\Models\Tenant\User;
use App\Services\ApplicationLogMaintenanceService;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $this->admin = User::create([
        'name' => 'Log Maintenance Admin',
        'email' => 'log-maint-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]);
});

test('application log maintenance catalog lists app and host targets', function () {
    $catalog = collect(app(ApplicationLogMaintenanceService::class)->catalog())->keyBy('key');

    expect($catalog)->toHaveKeys(['application', 'scheduler', 'queue', 'reverb', 'nginx_access', 'nginx_error', 'php_fpm', 'syslog'])
        ->and($catalog['application']['group'])->toBe('app')
        ->and($catalog['nginx_access']['group'])->toBe('host');
});

test('clear truncates writable application logs and keeps the file', function () {
    $path = base_path('storage/logs/laravel.log');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, "noise\n".str_repeat('x', 200));

    expect(filesize($path))->toBeGreaterThan(0);

    $result = app(ApplicationLogMaintenanceService::class)->clear(['application']);

    expect($result['cleared'])->toContain('application')
        ->and(is_file($path))->toBeTrue()
        ->and(filesize($path))->toBe(0);
});

test('readTail returns the end of a writable application log', function () {
    $path = base_path('storage/logs/laravel.log');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }

    $marker = 'TAIL_MARKER_'.uniqid();
    file_put_contents($path, str_repeat("old-line\n", 5000).$marker."\n");

    $payload = app(ApplicationLogMaintenanceService::class)->readTail('application', maxBytes: 2048);

    expect($payload['readable'])->toBeTrue()
        ->and($payload['truncated'])->toBeTrue()
        ->and($payload['content'])->toContain($marker)
        ->and(strlen($payload['content']))->toBeLessThan(3000);
});

test('system maintenance page exposes log clear controls for admins', function () {
    $path = base_path('storage/logs/laravel.log');
    if (! is_dir(dirname($path))) {
        mkdir(dirname($path), 0775, true);
    }
    file_put_contents($path, "noise\nview-me\n");

    Livewire::actingAs($this->admin, 'tenant')
        ->test(SystemMaintenancePage::class, ['embedded' => true])
        ->assertSuccessful()
        ->assertSee(__('Server logs'))
        ->assertSee(__('View'))
        ->assertSee(__('Clear app logs'))
        ->assertDontSee(__('Clear all writable logs'))
        ->mountAction('viewApplicationLog', ['key' => 'application'])
        ->assertMountedActionModalSee('view-me')
        ->unmountAction()
        ->call('setAdvancedUi', true)
        ->assertSee(__('Clear all writable logs'))
        ->callAction('clearAppLogs')
        ->assertSuccessful()
        ->assertNotified(__('Logs cleared'));
});
