<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\JobsPage;
use App\Models\Tenant\SystemJobRun;
use App\Models\Tenant\User;
use App\Support\ScheduledJobRegistry;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $this->actingAs(User::create([
        'name' => 'Jobs Catalog Tester',
        'email' => 'jobs-catalog-cols@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');
});

test('scheduled jobs catalog shows last started and duration from system job runs', function () {
    $definition = collect(ScheduledJobRegistry::all())
        ->firstWhere('key', 'announcements:dispatch-scheduled')
        ?? ScheduledJobRegistry::all()[0];

    SystemJobRun::query()->delete();

    $started = now()->setTime(10, 41, 2);

    SystemJobRun::create([
        'job_key' => $definition['key'],
        'command' => $definition['command'],
        'trigger' => SystemJobRun::TRIGGER_SCHEDULE,
        'status' => SystemJobRun::STATUS_SUCCESS,
        'exit_code' => 0,
        'started_at' => $started,
        'finished_at' => $started->copy()->addMilliseconds(125),
        'duration_ms' => 125,
        'output' => 'ok',
    ]);

    Livewire::test(JobsPage::class)
        ->call('setAdvancedUi', true)
        ->call('setJobsTab', 'catalog')
        ->assertOk()
        ->assertSee(__('Scheduled jobs'))
        ->assertSee(__('Last started'))
        ->assertSee(__('Duration'))
        ->assertSee('125 ms')
        ->assertSee((string) $started->year);
});

test('run history table shows started and duration columns', function () {
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
        'duration_ms' => 340,
        'output' => 'OK',
    ]);

    Livewire::test(JobsPage::class)
        ->call('setAdvancedUi', true)
        ->call('setJobsTab', 'history')
        ->assertOk()
        ->assertSee(__('Run history'))
        ->assertSee(__('Started'))
        ->assertSee(__('Duration'))
        ->assertSee('340 ms');
});
