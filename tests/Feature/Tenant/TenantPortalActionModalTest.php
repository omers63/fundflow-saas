<?php

declare(strict_types=1);

use App\Filament\Support\Action;
use App\Filament\Tenant\Support\TenantPortalActionModal;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;

beforeEach(function (): void {
    Filament::setCurrentPanel('tenant');
});

it('styles tenant confirmation actions with a compact custom body', function (): void {
    $action = Action::make('delete_row')
        ->requiresConfirmation()
        ->modalHeading(__('Delete row'))
        ->modalDescription(__('This cannot be undone.'));

    $styled = TenantPortalActionModal::applyConfirmation($action);

    $classes = (string) $styled->getExtraModalWindowAttributeBag()->get('class');

    expect($classes)->toContain('ff-tenant-confirm-modal-window')
        ->and($styled->hasModalContent())->toBeTrue()
        ->and($styled->hasModalContentFooter())->toBeFalse();
});

it('defers tenant confirmation styling until the tenant panel is active at render time', function (): void {
    Filament::setCurrentPanel(null);

    $action = DeleteAction::make();
    $styled = TenantPortalActionModal::applyConfirmation($action);

    expect((string) $styled->getExtraModalWindowAttributeBag()->get('class'))->not->toContain('ff-tenant-confirm-modal-window')
        ->and($styled->getModalContent())->toBeNull();

    Filament::setCurrentPanel('tenant');

    expect((string) $styled->getExtraModalWindowAttributeBag()->get('class'))
        ->toContain('ff-tenant-confirm-modal-window')
        ->and((string) $styled->getExtraModalWindowAttributeBag()->get('class'))
        ->toContain('ff-tenant-confirm-modal-window--danger')
        ->and($styled->getModalContent())->not->toBeNull();
});

it('adds a progress footer only for long-running tenant actions', function (): void {
    $instant = Action::make('delete_row')->requiresConfirmation();

    expect(TenantPortalActionModal::shouldShowProgress($instant))->toBeFalse();

    $long = Action::make('run_realtime')
        ->longRunning()
        ->longRunningMessage(__('Running real-time reconciliation checks and saving a snapshot.'));

    expect(TenantPortalActionModal::shouldShowProgress($long))->toBeTrue();

    $styled = TenantPortalActionModal::applyProgressFooter($long);

    expect($styled->hasModalContentFooter())->toBeTrue();
});

it('adds progress to confirmations that declare long-running copy', function (): void {
    $message = __('Running the nightly reconciliation batch. This can take a minute on large tenants.');

    $action = Action::make('run_nightly')
        ->requiresConfirmation()
        ->longRunningMessage($message);

    expect(TenantPortalActionModal::shouldShowProgress($action))->toBeTrue();

    $styled = TenantPortalActionModal::applyConfirmation($action);

    expect($styled->hasModalContentFooter())->toBeTrue();

    $view = TenantPortalActionModal::progressFooterView($action);

    expect($view->name())->toBe('filament.tenant.partials.action-modal-progress')
        ->and($view->getData()['message'])->toBe($message);
});

it('renders confirmation modal view data from the action at runtime', function (): void {
    $action = Action::make('runMigration')
        ->label(__('Run migration'))
        ->requiresConfirmation()
        ->modalHeading(__('Run migration now?'))
        ->modalDescription(__('This writes members, loans, and optional payments to the database.'));

    $styled = TenantPortalActionModal::applyConfirmation($action);
    $view = $styled->getModalContent();

    expect($view)->not->toBeNull()
        ->and($view->name())->toBe('filament.tenant.partials.action-confirm-modal')
        ->and($view->getData()['heading'])->toBe(__('Run migration now?'))
        ->and($view->getData()['description'])->toBe(__('This writes members, loans, and optional payments to the database.'));
});
