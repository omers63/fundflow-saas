<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\JobsPage;
use App\Models\Tenant\SystemJobRun;
use App\Models\Tenant\User;
use App\Services\SystemJobRunnerService;
use App\Support\AutomationAreaSummary;
use App\Support\ScheduledJobRegistry;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $this->actingAs(User::create([
        'name' => 'Automation Status Tester',
        'email' => 'automation-status@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');
});

test('automation area summary includes per-job schedule and run status', function () {
    $definition = collect(ScheduledJobRegistry::all())
        ->firstWhere('category', 'contributions');

    SystemJobRun::query()->delete();

    SystemJobRun::create([
        'job_key' => $definition['key'],
        'command' => $definition['command'],
        'trigger' => SystemJobRun::TRIGGER_SCHEDULE,
        'status' => SystemJobRun::STATUS_SUCCESS,
        'exit_code' => 0,
        'started_at' => now()->subHour(),
        'finished_at' => now()->subHour()->addSeconds(2),
        'duration_ms' => 2000,
        'output' => 'ok',
    ]);

    $areas = AutomationAreaSummary::summarize(app(SystemJobRunnerService::class));
    $collections = collect($areas)->firstWhere('area', 'contributions');

    expect($collections)->not->toBeNull()
        ->and($collections['jobs'])->not->toBeEmpty()
        ->and(collect($collections['jobs'])->pluck('job_label')->all())->toContain($definition['label'])
        ->and(collect($collections['jobs'])->firstWhere('key', $definition['key'])['last_status'])
        ->toBe(SystemJobRun::STATUS_SUCCESS)
        ->and(collect($collections['jobs'])->firstWhere('key', $definition['key'])['schedule'])
        ->toBeString()
        ->not->toBeEmpty();
});

test('jobs status page lists each area job with schedule and run status', function () {
    $definition = collect(ScheduledJobRegistry::all())
        ->firstWhere('key', 'bank:auto-match');

    SystemJobRun::query()->delete();

    SystemJobRun::create([
        'job_key' => $definition['key'],
        'command' => $definition['command'],
        'trigger' => SystemJobRun::TRIGGER_SCHEDULE,
        'status' => SystemJobRun::STATUS_FAILED,
        'exit_code' => 1,
        'started_at' => now()->subMinutes(30),
        'finished_at' => now()->subMinutes(29),
        'duration_ms' => 1000,
        'output' => 'fail',
    ]);

    Livewire::test(JobsPage::class)
        ->assertOk()
        ->assertSet('jobsTab', 'status')
        ->assertSee(__('Contributions'))
        ->assertSee(__('Bank'))
        ->assertSee($definition['label'])
        ->assertSee($definition['schedule'])
        ->assertSee(__('Failed'));
});
