<?php

declare(strict_types=1);

use App\Filament\Support\Action;
use App\Filament\Support\LoanDelinquencyHeaderActions;
use App\Filament\Tenant\Support\TenantPortalActionModal;
use Filament\Actions\DeleteAction;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Illuminate\Support\Facades\App;

beforeEach(function (): void {
    Filament::setCurrentPanel('tenant');
    App::setLocale('en');
});

it('styles confirmation actions without replacing modal body', function (): void {
    $action = Action::make('delete_row')
        ->requiresConfirmation()
        ->modalHeading(__('Delete row'))
        ->modalDescription(__('This cannot be undone.'));

    $styled = TenantPortalActionModal::applyConfirmation($action);

    $classes = (string) $styled->getExtraModalWindowAttributeBag()->get('class');

    expect($classes)->toContain('ff-confirm-modal-window')
        ->and($classes)->toContain('ff-confirm-modal-window--native')
        ->and($styled->hasModalContent())->toBeFalse()
        ->and($styled->hasModalContentFooter())->toBeFalse();
});

it('keeps confirmation dialogs above portal bottom chrome and centers them on compact viewports', function (): void {
    $css = file_get_contents(resource_path('css/filament/confirm-modals.css'));

    expect($css)
        ->toContain('z-index: 70')
        ->toContain('grid-template-rows: 1fr auto 1fr')
        ->toContain('100dvh')
        ->toContain('safe-area-inset-bottom');
});

it('styles confirmations on member and admin panels too', function (string $panel): void {
    Filament::setCurrentPanel($panel);

    $action = Action::make('delete_row')
        ->requiresConfirmation()
        ->modalHeading(__('Delete row'));

    $styled = TenantPortalActionModal::applyConfirmation($action);
    $classes = (string) $styled->getExtraModalWindowAttributeBag()->get('class');

    expect($classes)->toContain('ff-confirm-modal-window')
        ->and($classes)->toContain('ff-confirm-modal-window--native')
        ->and($styled->hasFormWrapper())->toBeFalse()
        ->and($styled->isModalAutofocused())->toBeFalse();
})->with(['member', 'admin']);

it('defers confirmation styling until a Filament panel is active at render time', function (): void {
    Filament::setCurrentPanel(null);

    $action = DeleteAction::make();
    $styled = TenantPortalActionModal::applyConfirmation($action);

    expect((string) $styled->getExtraModalWindowAttributeBag()->get('class'))->not->toContain('ff-confirm-modal-window');

    Filament::setCurrentPanel('tenant');

    expect((string) $styled->getExtraModalWindowAttributeBag()->get('class'))
        ->toContain('ff-confirm-modal-window')
        ->and((string) $styled->getExtraModalWindowAttributeBag()->get('class'))
        ->toContain('ff-confirm-modal-window--danger');
});

it('adds a progress footer only for long-running actions', function (): void {
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

    expect($styled->hasModalContent())->toBeFalse()
        ->and($styled->hasModalContentFooter())->toBeTrue();

    $view = TenantPortalActionModal::progressFooterView($action);

    expect($view->name())->toBe('filament.partials.action-modal-progress')
        ->and($view->getData()['message'])->toBe($message);
});

it('shows progress while the delinquency check confirmation runs', function (): void {
    $action = LoanDelinquencyHeaderActions::runMaintenance();
    $message = __('Running delinquency check. This can take a minute on large funds.');

    expect(TenantPortalActionModal::shouldShowProgress($action))->toBeTrue()
        ->and($action->getLongRunningMessage())->toBe($message);

    $action->boot();

    expect($action->hasModalContent())->toBeFalse()
        ->and($action->hasModalContentFooter())->toBeTrue();

    $html = $action->getModalContentFooter()?->render() ?? '';

    expect($html)->toContain('ff-action-modal-progress')
        ->and($html)->toContain(__('Running delinquency check. This can take a minute on large funds.'));
});

it('marks confirmation modals with fields and ignores empty schemas', function (): void {
    $withFields = TenantPortalActionModal::applyConfirmation(
        Action::make('approve_with_date')
            ->requiresConfirmation()
            ->schema([
                TextInput::make('note'),
            ]),
    );

    $emptySchema = TenantPortalActionModal::applyConfirmation(
        Action::make('approve_plain')
            ->requiresConfirmation()
            ->schema([]),
    );

    $noSchema = TenantPortalActionModal::applyConfirmation(
        Action::make('approve_bare')->requiresConfirmation(),
    );

    expect((string) $withFields->getExtraModalWindowAttributeBag()->get('class'))
        ->toContain('ff-confirm-modal-window--with-fields')
        ->and($withFields->hasFormWrapper())->toBeTrue()
        ->and((string) $emptySchema->getExtraModalWindowAttributeBag()->get('class'))
        ->not->toContain('ff-confirm-modal-window--with-fields')
        ->and($emptySchema->hasFormWrapper())->toBeFalse()
        ->and((string) $noSchema->getExtraModalWindowAttributeBag()->get('class'))
        ->not->toContain('ff-confirm-modal-window--with-fields')
        ->and($noSchema->hasFormWrapper())->toBeFalse();
});

it('keeps the modal confirm button as a distinct submit action (not the parent)', function (): void {
    $action = Action::make('pause_scheduler')
        ->label(__('Pause scheduler'))
        ->requiresConfirmation()
        ->modalHeading(__('Pause scheduled automation?'));

    $action->boot();

    $submit = $action->getModalSubmitAction();

    expect($submit)->not->toBeNull()
        ->and($submit->getName())->toBe('submit')
        ->and($submit->getName())->not->toBe($action->getName())
        ->and($submit)->not->toBe($action)
        ->and($submit->getLabel())->toBe(__('filament-actions::modal.actions.confirm.label'));
});

it('defers modal wiring for app actions until boot', function (): void {
    $action = LoanDelinquencyHeaderActions::runMaintenance();

    expect($action->hasModalContentFooter())->toBeFalse()
        ->and(TenantPortalActionModal::shouldShowProgress($action))->toBeTrue();

    $action->boot();

    expect($action->hasModalContentFooter())->toBeTrue();
});
