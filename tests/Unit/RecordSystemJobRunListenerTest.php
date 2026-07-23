<?php

declare(strict_types=1);

use App\Listeners\RecordSystemJobRunListener;
use App\Models\Tenant\SystemJobRun;
use App\Support\ScheduledJobRegistry;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    RecordSystemJobRunListener::resumeRecording();
});

test('scheduled tenant command run is recorded without cache tags', function () {
    $this->initializeTenancy();

    $definition = ScheduledJobRegistry::all()[0];
    $commandName = explode(' ', $definition['command'])[0];

    $listener = new RecordSystemJobRunListener;

    $input = new ArrayInput([]);
    $output = new BufferedOutput;

    $listener->handleStarting(new CommandStarting($commandName, $input, $output));
    $listener->handleFinished(new CommandFinished($commandName, $input, $output, 0));

    expect(SystemJobRun::query()->where('job_key', $definition['key'])->exists())->toBeTrue();
});

test('tenant aware scheduled command records a system job run per tenant', function () {
    $this->initializeTenancy();
    SystemJobRun::query()->delete();

    $tenantId = (string) tenant('id');

    Artisan::call('announcements:dispatch-scheduled', [
        '--tenants' => [$tenantId],
    ]);

    expect(SystemJobRun::query()
        ->where('job_key', 'announcements:dispatch-scheduled')
        ->where('trigger', SystemJobRun::TRIGGER_SCHEDULE)
        ->where('status', SystemJobRun::STATUS_SUCCESS)
        ->exists())->toBeTrue();
});

test('registry resolves fund reconcile daily vs monthly by options', function () {
    $daily = ScheduledJobRegistry::findForInput(
        'fund:reconcile',
        new ArrayInput(['--daily' => true]),
    );
    $monthly = ScheduledJobRegistry::findForInput(
        'fund:reconcile',
        new ArrayInput(['--monthly' => true]),
    );

    expect($daily['key'])->toBe('fund:reconcile --daily')
        ->and($monthly['key'])->toBe('fund:reconcile --monthly');
});

test('suppressed recording skips listener inserts', function () {
    $this->initializeTenancy();
    SystemJobRun::query()->delete();

    $definition = ScheduledJobRegistry::all()[0];
    $commandName = explode(' ', $definition['command'])[0];
    $listener = new RecordSystemJobRunListener;
    $input = new ArrayInput([]);
    $output = new BufferedOutput;

    RecordSystemJobRunListener::suppressRecording();

    try {
        $listener->handleStarting(new CommandStarting($commandName, $input, $output));
        $listener->handleFinished(new CommandFinished($commandName, $input, $output, 0));
    } finally {
        RecordSystemJobRunListener::resumeRecording();
    }

    expect(SystemJobRun::query()->where('job_key', $definition['key'])->exists())->toBeFalse();
});
