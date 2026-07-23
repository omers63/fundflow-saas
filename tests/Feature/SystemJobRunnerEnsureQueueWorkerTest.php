<?php

declare(strict_types=1);

use App\Models\Tenant\SystemJobRun;
use App\Services\SystemJobRunnerService;
use Illuminate\Support\Facades\Artisan;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();

    config([
        'queue.default' => 'sync',
        'queue.worker_watchdog.enabled' => true,
    ]);
});

test('manual run of queue ensure worker under tenancy does not pass tenants option', function (): void {
    SystemJobRun::query()->delete();

    $result = app(SystemJobRunnerService::class)->run('queue:ensure-worker');

    expect($result['exit_code'])->toBe(0)
        ->and($result['run']->status)->toBe(SystemJobRun::STATUS_SUCCESS)
        ->and($result['run']->output ?? '')->not->toContain('The "--tenants" option does not exist.');
});

test('system job runner only adds tenants parameter when command defines it', function (): void {
    $runner = app(SystemJobRunnerService::class);
    $method = new ReflectionMethod($runner, 'artisanParametersFor');
    $method->setAccessible(true);

    expect($method->invoke($runner, 'queue:ensure-worker'))->toBe([])
        ->and(Artisan::all()['queue:ensure-worker']->getDefinition()->hasOption('tenants'))->toBeFalse();

    $tenantAware = collect(Artisan::all())
        ->first(fn ($command): bool => $command->getDefinition()->hasOption('tenants'));

    expect($tenantAware)->not->toBeNull();

    expect($method->invoke($runner, $tenantAware->getName()))->toBe([
        '--tenants' => [(string) tenant('id')],
    ]);
});
