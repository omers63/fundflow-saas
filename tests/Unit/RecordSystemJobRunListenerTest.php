<?php

declare(strict_types=1);

use App\Listeners\RecordSystemJobRunListener;
use App\Models\Tenant\SystemJobRun;
use App\Support\ScheduledJobRegistry;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Concerns\InitializesTenancy;
use Tests\TestCase;

uses(TestCase::class, InitializesTenancy::class);

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
