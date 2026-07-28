<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Filament\Tenant\Pages\JobsPage;
use App\Models\Tenant\SystemJobRun;
use App\Models\Tenant\User;
use App\Support\AutomationSchedulerGate;
use App\Support\ScheduledJobRegistry;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    app(AutomationSchedulerGate::class)->resume();
    SystemJobRun::query()->delete();

    $this->actingAs(User::create([
        'name' => 'Automation Controls Admin',
        'email' => 'automation-controls-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');
});

test('jobs page can pause and resume the scheduler', function () {
    $component = Livewire::test(JobsPage::class)
        ->assertActionVisible('pause_scheduler')
        ->assertActionHidden('resume_scheduler');

    foreach ($component->instance()->getCachedHeaderActions() as $action) {
        expect($action->isIconButton())->toBeTrue()
            ->and($action->getTooltip())->not->toBeEmpty();
    }

    $component
        ->callAction('pause_scheduler')
        ->assertSuccessful();

    expect(app(AutomationSchedulerGate::class)->isPaused())->toBeTrue();

    Livewire::test(JobsPage::class)
        ->assertSee(__('Scheduler paused'))
        ->assertActionVisible('resume_scheduler')
        ->assertActionHidden('pause_scheduler')
        ->callAction('resume_scheduler')
        ->assertSuccessful();

    expect(app(AutomationSchedulerGate::class)->isPaused())->toBeFalse();
});

test('audit system header actions appear only on the automation tab', function () {
    $component = Livewire::test(AuditSystemPage::class)
        ->assertSet('sideTab', 'audit');

    expect($component->instance()->getCachedHeaderActions())->toBeEmpty();

    $component->call('setSideTab', 'jobs')
        ->assertSet('sideTab', 'jobs')
        ->assertActionVisible('pause_scheduler');

    expect(collect($component->instance()->getCachedHeaderActions())->map->getName()->all())
        ->toContain('pause_scheduler')
        ->toContain('open_reconciliation');

    $component->call('setSideTab', 'notifications')
        ->assertSet('sideTab', 'notifications');

    expect($component->instance()->getCachedHeaderActions())->toBeEmpty();
});

test('jobs page clears finished run history from the history tab', function () {
    $definition = ScheduledJobRegistry::all()[0];

    SystemJobRun::create([
        'job_key' => $definition['key'],
        'command' => $definition['command'],
        'trigger' => SystemJobRun::TRIGGER_MANUAL,
        'status' => SystemJobRun::STATUS_SUCCESS,
        'exit_code' => 0,
        'started_at' => now(),
        'finished_at' => now(),
        'duration_ms' => 50,
        'output' => 'OK',
    ]);

    Livewire::test(JobsPage::class)
        ->call('setAdvancedUi', true)
        ->call('setJobsTab', 'history')
        ->assertSee(__('Run history'))
        ->assertSee($definition['label'])
        ->callAction('clear_run_history')
        ->assertSuccessful()
        ->assertDontSee($definition['label']);

    expect(SystemJobRun::query()->count())->toBe(0);
});
