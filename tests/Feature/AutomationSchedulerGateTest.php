<?php

declare(strict_types=1);

use App\Models\Tenant\SystemJobRun;
use App\Services\SystemJobRunnerService;
use App\Support\AutomationSchedulerGate;
use App\Support\ScheduledJobRegistry;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    app(AutomationSchedulerGate::class)->resume();
    SystemJobRun::query()->delete();
});

test('automation scheduler gate pauses and resumes via settings', function () {
    $gate = app(AutomationSchedulerGate::class);

    expect($gate->isPaused())->toBeFalse();

    $gate->pause('Maintenance window');

    expect($gate->isPaused())->toBeTrue()
        ->and($gate->reason())->toBe('Maintenance window');

    $gate->resume();

    expect($gate->isPaused())->toBeFalse()
        ->and($gate->reason())->toBeNull();
});

test('clear run history deletes finished rows and keeps running ones', function () {
    $definition = ScheduledJobRegistry::all()[0];

    SystemJobRun::create([
        'job_key' => $definition['key'],
        'command' => $definition['command'],
        'trigger' => SystemJobRun::TRIGGER_SCHEDULE,
        'status' => SystemJobRun::STATUS_SUCCESS,
        'exit_code' => 0,
        'started_at' => now()->subMinute(),
        'finished_at' => now()->subMinute(),
        'duration_ms' => 10,
    ]);

    SystemJobRun::create([
        'job_key' => $definition['key'],
        'command' => $definition['command'],
        'trigger' => SystemJobRun::TRIGGER_MANUAL,
        'status' => SystemJobRun::STATUS_RUNNING,
        'started_at' => now(),
    ]);

    $deleted = app(SystemJobRunnerService::class)->clearRunHistory();

    expect($deleted)->toBe(1)
        ->and(SystemJobRun::query()->count())->toBe(1)
        ->and(SystemJobRun::query()->value('status'))->toBe(SystemJobRun::STATUS_RUNNING);
});
