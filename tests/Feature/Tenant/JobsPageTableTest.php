<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\JobsPage;
use App\Models\Tenant\SystemJobRun;
use App\Models\Tenant\User;
use App\Services\SystemJobRunnerService;
use App\Support\ScheduledJobRegistry;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $this->actingAs(User::create([
        'name' => 'Jobs Tester',
        'email' => 'jobs-table@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');
});

test('scheduled job catalog rows include display fields', function () {
    $runner = app(SystemJobRunnerService::class);
    $definitions = ScheduledJobRegistry::all();

    expect($definitions)->not->toBeEmpty();

    $row = $runner->catalogRow($definitions[0]);

    expect($row['job_label'])->toBeString()->not->toBeEmpty()
        ->and($row['schedule'])->toBeString()->not->toBeEmpty()
        ->and($row['category'])->toBeString()->not->toBeEmpty();

    $records = $runner->catalogRecords();

    expect($records)->toHaveCount(count($definitions))
        ->and($records->has($definitions[0]['key']))->toBeTrue();
});

test('jobs page renders scheduled job labels in catalog table', function () {
    $label = ScheduledJobRegistry::all()[0]['label'];

    Livewire::test(JobsPage::class)
        ->assertOk()
        ->assertSee(__('Scheduled jobs'))
        ->assertSee($label);
});

test('run history tab renders after switching from catalog', function () {
    $definition = ScheduledJobRegistry::all()[0];

    SystemJobRun::query()->delete();

    SystemJobRun::create([
        'job_key' => $definition['key'],
        'command' => $definition['command'],
        'trigger' => SystemJobRun::TRIGGER_MANUAL,
        'status' => SystemJobRun::STATUS_SUCCESS,
        'exit_code' => 0,
        'started_at' => now(),
        'finished_at' => now(),
        'duration_ms' => 120,
        'output' => 'OK',
    ]);

    Livewire::test(JobsPage::class)
        ->assertSee(__('Scheduled jobs'))
        ->assertSee($definition['label'])
        ->call('setJobsTab', 'history')
        ->assertSee(__('Run history'))
        ->assertSee($definition['label'])
        ->assertSee(SystemJobRun::STATUS_SUCCESS)
        ->call('setJobsTab', 'catalog')
        ->assertSee(__('Scheduled jobs'))
        ->assertSee($definition['label']);
});
