<?php

declare(strict_types=1);

use App\Services\QueueWorkerSupervisor;
use App\Support\ScheduledJobRegistry;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Process;

beforeEach(function (): void {
    config([
        'queue.default' => 'database',
        'queue.worker_watchdog.enabled' => true,
        'queue.worker_watchdog.connection' => null,
        'queue.worker_watchdog.sleep' => 3,
        'queue.worker_watchdog.tries' => 3,
        'queue.worker_watchdog.max_time' => 3600,
        'queue.worker_watchdog.backoff' => 10,
    ]);
});

test('queue ensure worker command is registered', function (): void {
    expect(Artisan::all())->toHaveKey('queue:ensure-worker');
});

test('queue ensure worker command is listed in scheduled job registry', function (): void {
    $keys = array_column(ScheduledJobRegistry::all(), 'key');

    expect($keys)->toContain('queue:ensure-worker');
});

test('queue ensure worker command reports when watchdog is disabled', function (): void {
    config(['queue.default' => 'sync']);

    Artisan::call('queue:ensure-worker');

    expect(Artisan::output())->toContain(__('Queue worker watchdog is disabled or not needed for the current queue driver.'));
});

test('queue ensure worker command reports when worker is already running', function (): void {
    Process::fake([
        '*' => Process::result(output: "12345\n"),
    ]);

    Artisan::call('queue:ensure-worker');

    expect(Artisan::output())->toContain(__('Queue worker is already running.'));
});

test('queue worker supervisor starts worker when none is running', function (): void {
    Process::fake([
        'pgrep -f *' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    $result = app(QueueWorkerSupervisor::class)->ensureWorkerRunning();

    expect($result)->toBe([
        'restarted' => true,
        'started' => true,
    ]);
});

test('queue ensure worker command starts worker when none is running', function (): void {
    Process::fake([
        'pgrep -f *' => Process::result(exitCode: 1),
        '*' => Process::result(),
    ]);

    $this->artisan('queue:ensure-worker')
        ->expectsOutputToContain(__('Queue worker was not running. Issued queue:restart and started queue:work in the background.'))
        ->assertSuccessful();
});

test('queue ensure worker command is scheduled every minute', function (): void {
    $event = collect(app(Schedule::class)->events())
        ->first(fn ($scheduled): bool => str_contains((string) $scheduled->command, 'queue:ensure-worker'));

    expect($event)->not->toBeNull();
});

test('queue worker supervisor skips sync queue driver', function (): void {
    config(['queue.default' => 'sync']);

    $supervisor = app(QueueWorkerSupervisor::class);

    expect($supervisor->shouldSupervise())->toBeFalse()
        ->and($supervisor->isWorkerRunning())->toBeTrue();
});

test('queue worker supervisor builds worker command arguments from config', function (): void {
    config([
        'queue.default' => 'redis',
        'queue.worker_watchdog.connection' => 'redis',
        'queue.worker_watchdog.sleep' => 5,
        'queue.worker_watchdog.tries' => 2,
        'queue.worker_watchdog.max_time' => 1800,
        'queue.worker_watchdog.backoff' => 15,
    ]);

    $supervisor = app(QueueWorkerSupervisor::class);

    expect($supervisor->workerCommandArguments())->toBe([
        'queue:work',
        'redis',
        '--sleep=5',
        '--tries=2',
        '--max-time=1800',
        '--backoff=15',
    ]);
});
