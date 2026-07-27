<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\AuditSystemPage;
use App\Filament\Tenant\Pages\JobsPage;
use App\Models\Tenant\Setting;
use App\Models\Tenant\User;
use App\Support\AutomationScheduleSettings;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function () {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');

    $this->actingAs(User::create([
        'name' => 'Schedule Admin',
        'email' => 'schedule-admin-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
    ]), 'tenant');
});

test('jobs page schedule tab shows consolidated automation configuration', function () {
    Livewire::test(JobsPage::class)
        ->assertSee(__('Schedule'))
        ->call('setJobsTab', 'schedule')
        ->assertSet('jobsTab', 'schedule')
        ->assertSee(__('Cycle & month boundary'))
        ->assertSee(__('Job schedule'))
        ->assertSee(__('Fixed & manual jobs'))
        ->assertSee(__('Automation behaviour'))
        ->assertSee(__('Automation notifications'))
        ->assertSee(__('Push loan schedule (grace): manual only (one-time). Ensure queue worker: every minute on the host (system).'));
});

test('jobs page can save automation schedule from the schedule tab', function () {
    $defaults = AutomationScheduleSettings::allForForm();

    Livewire::test(JobsPage::class)
        ->call('setJobsTab', 'schedule')
        ->fillForm([
            'cycle_start_day' => 8,
            'automation_master_invariants_frequency' => 'weekly',
            'automation_master_invariants_weekdays' => [1, 3],
            'automation_master_invariants_times' => '06:15',
            'automation_auto_accept_deposits' => false,
        ], 'automationScheduleForm')
        ->call('saveAutomationSchedule')
        ->assertSuccessful()
        ->assertNotified(__('Automation schedule saved'));

    expect((int) Setting::get('contribution', 'cycle_start_day'))->toBe(8)
        ->and(AutomationScheduleSettings::masterInvariantsFrequency())->toBe('weekly')
        ->and(AutomationScheduleSettings::masterInvariantsWeekdays())->toBe([1, 3])
        ->and(AutomationScheduleSettings::masterInvariantsTimes())->toBe(['06:15'])
        ->and(AutomationScheduleSettings::autoAcceptDeposits())->toBeFalse()
        ->and(AutomationScheduleSettings::contributionDueNotifyTime())->toBe($defaults['automation_contribution_due_notify_time']);
});

test('audit system automation side tab exposes the schedule form', function () {
    Livewire::test(AuditSystemPage::class, ['sideTab' => 'jobs', 'jobsTab' => 'schedule'])
        ->assertSet('sideTab', 'jobs')
        ->assertSet('jobsTab', 'schedule')
        ->assertSee(__('Cycle & month boundary'))
        ->assertSee(__('Save schedule'));
});
