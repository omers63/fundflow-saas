<?php

declare(strict_types=1);

use App\Filament\Tenant\Pages\DelinquencyWorkspacePage;
use App\Filament\Tenant\Pages\JobsPage;
use App\Filament\Tenant\Support\TenantPortalActionModal;
use App\Models\Tenant\User;
use App\Support\AutomationSchedulerGate;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\App;
use Livewire\Livewire;
use Tests\Concerns\InitializesTenancy;

uses(InitializesTenancy::class);

beforeEach(function (): void {
    $this->initializeTenancy();
    Filament::setCurrentPanel('tenant');
    App::setLocale('en');

    $this->actingAs(User::create([
        'name' => 'Modal Admin',
        'email' => 'modal-admin-'.uniqid().'@fund.test',
        'password' => bcrypt('password'),
        'email_verified_at' => now(),
        'is_admin' => true,
        'preferred_locale' => 'en',
    ]), 'tenant');
});

it('mounts delinquency check with a visible confirmation modal and progress footer', function (): void {
    $component = Livewire::test(DelinquencyWorkspacePage::class, ['sideTab' => 'overview'])
        ->assertSuccessful()
        ->assertSeeHtml('wire:partial="action-modals"')
        ->mountAction('runDelinquencyMaintenance')
        ->assertActionMounted('runDelinquencyMaintenance');

    $action = $component->instance()->getMountedAction();

    expect($action)->not->toBeNull()
        ->and($action->isConfirmationRequired())->toBeTrue()
        ->and($action->shouldOpenModal())->toBeTrue()
        ->and(TenantPortalActionModal::shouldShowProgress($action))->toBeTrue()
        ->and($action->hasModalContentFooter())->toBeTrue();

    $html = $action->getModalContentFooter()?->render() ?? '';

    expect($html)->toContain('ff-action-modal-progress')
        ->and($html)->toContain(__('Running delinquency check. This can take a minute on large funds.'));
});

it('mounts scheduler toggle with a confirmation modal', function (): void {
    app(AutomationSchedulerGate::class)->resume();

    $component = Livewire::test(JobsPage::class)
        ->assertSuccessful()
        ->assertSeeHtml('wire:partial="action-modals"')
        ->assertActionVisible('toggle_scheduler')
        ->mountAction('toggle_scheduler')
        ->assertActionMounted('toggle_scheduler');

    $action = $component->instance()->getMountedAction();
    $submit = $action?->getModalSubmitAction();

    expect($action)->not->toBeNull()
        ->and($action->isConfirmationRequired())->toBeTrue()
        ->and($action->shouldOpenModal())->toBeTrue()
        ->and($action->getModalHeading())->toBe(__('Pause scheduled automation?'))
        ->and($submit)->not->toBeNull()
        ->and($submit->getName())->toBe('submit')
        ->and($submit)->not->toBe($action);

    expect(app(AutomationSchedulerGate::class)->isPaused())->toBeFalse();
});

it('toggles scheduler pause state from a single header action', function (): void {
    app(AutomationSchedulerGate::class)->resume();

    $component = Livewire::test(JobsPage::class)
        ->callAction('toggle_scheduler')
        ->assertSuccessful();

    expect(app(AutomationSchedulerGate::class)->isPaused())->toBeTrue();

    $component
        ->callAction('toggle_scheduler')
        ->assertSuccessful();

    expect(app(AutomationSchedulerGate::class)->isPaused())->toBeFalse();
});
