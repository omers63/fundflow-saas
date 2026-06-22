<?php

declare(strict_types=1);

use App\Filament\Support\Action;
use App\Filament\Tenant\Support\TenantPortalActionModal;
use Filament\Facades\Filament;

beforeEach(function (): void {
    Filament::setCurrentPanel('tenant');
});

it('styles tenant confirmation actions and adds a progress footer', function (): void {
    $action = Action::make('delete_row')
        ->requiresConfirmation()
        ->modalHeading(__('Delete row'));

    $styled = TenantPortalActionModal::applyConfirmation($action);

    $classes = (string) $styled->getExtraModalWindowAttributeBag()->get('class');

    expect($classes)->toContain('ff-tenant-confirm-modal-window')
        ->and($styled->hasModalContentFooter())->toBeTrue();
});

it('adds a progress footer for long-running tenant actions', function (): void {
    $action = Action::make('run_realtime')
        ->longRunning()
        ->longRunningMessage(__('Running real-time reconciliation checks and saving a snapshot.'));

    expect(TenantPortalActionModal::shouldShowProgress($action))->toBeTrue();

    $styled = TenantPortalActionModal::applyProgressFooter($action);

    expect($styled->hasModalContentFooter())->toBeTrue();
});

it('uses custom long-running copy in the progress footer view', function (): void {
    $message = __('Running the nightly reconciliation batch. This can take a minute on large tenants.');

    $action = Action::make('run_nightly')
        ->requiresConfirmation()
        ->longRunningMessage($message);

    $view = TenantPortalActionModal::progressFooterView($action);

    expect($view->name())->toBe('filament.tenant.partials.action-modal-progress')
        ->and($view->getData()['message'])->toBe($message);
});
