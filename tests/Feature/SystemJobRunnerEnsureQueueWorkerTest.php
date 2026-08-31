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

    expect($method->invoke($runner, 'fund:reconcile'))->toBe([
        '--tenants' => [(string) tenant('id')],
        '--force' => true,
    ]);
});

test('system job runner parses command flags before calling artisan', function (): void {
    $runner = app(SystemJobRunnerService::class);
    $method = new ReflectionMethod($runner, 'parseCommandInvocation');
    $method->setAccessible(true);

    expect($method->invoke($runner, 'fund:reconcile --daily'))->toBe([
        'fund:reconcile',
        ['--daily' => true],
    ])->and($method->invoke($runner, 'statements:generate --notify'))->toBe([
        'statements:generate',
        ['--notify' => true],
    ]);
});

test('manual run of daily reconcile job uses fund:reconcile with daily flag', function (): void {
    SystemJobRun::query()->delete();

    $result = app(SystemJobRunnerService::class)->run('fund:reconcile --daily');

    expect($result['exit_code'])->toBe(0)
        ->and($result['run']->status)->toBe(SystemJobRun::STATUS_SUCCESS)
        ->and($result['run']->output ?? '')->not->toContain('does not exist')
        ->and($result['run']->output ?? '')->not->toContain('is not defined');
});
