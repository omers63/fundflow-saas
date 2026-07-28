<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Filament\Support\Action as AppAction;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;

/**
 * App-wide confirmation / long-running modal helpers (all Filament panels).
 *
 * Confirmation uses Filament's native heading/description/icon. We only add
 * window classes + an always-visible progress footer for long-running work.
 * Replacing modal content while CSS hides `.fi-modal-header` caused blank modals.
 *
 * Critical: `modalSubmitAction(fn (Action $action) => …)` must name the parameter
 * `$action`. Filament injects the footer submit button by that name. A differently
 * named `Action` parameter receives the *parent* action by type-hint and replaces
 * Confirm with the original action (auto-run / missing Confirm).
 */
final class TenantPortalActionModal
{
    /**
     * @var list<string>
     */
    private const STYLED_PANELS = ['tenant', 'member', 'admin'];

    public static function applyConfirmation(Action $action): Action
    {
        $parent = $action;

        $action = $parent
            ->modalAutofocus(fn(): bool => !self::shouldStyle())
            ->formWrapper(fn(): bool => self::shouldStyle()
                ? self::hasFormFields($parent)
                : true)
            ->modalWidth(fn(): Width|string => self::shouldStyle()
                ? self::confirmationModalWidth($parent)
                : Width::Medium)
            ->extraModalWindowAttributes(
                fn(): array => self::shouldStyle()
                ? ['class' => self::confirmWindowClasses($parent)]
                : [],
                merge: true,
            )
            ->modalSubmitAction(function (Action $action) use ($parent): Action {
                return self::decorateConfirmSubmit($action, $parent);
            });

        if (self::shouldShowProgress($parent)) {
            return self::applyProgressFooter($action);
        }

        return $action;
    }

    private static function decorateConfirmSubmit(Action $submit, Action $parent): Action
    {
        if (!self::shouldStyle()) {
            return $submit;
        }

        return $submit
            ->color(self::confirmationSubmitColor($parent))
            ->extraAttributes([
                'wire:loading.attr' => 'disabled',
                'wire:target' => 'callMountedAction',
            ], merge: true);
    }

    public static function applyProgressFooter(Action $action): Action
    {
        if ($action->hasModalContentFooter()) {
            return $action;
        }

        return $action->modalContentFooter(
            fn(Action $action): ?View => self::shouldStyle() && self::shouldShowProgress($action)
            ? self::progressFooterView($action)
            : null,
        );
    }

    public static function shouldShowProgress(Action $action): bool
    {
        if (! $action instanceof AppAction) {
            return false;
        }

        if ($action->isLongRunning()) {
            return true;
        }

        return $action->isConfirmationRequired() && filled($action->getLongRunningMessage());
    }

    public static function progressFooterView(Action $action): View
    {
        return view('filament.partials.action-modal-progress', [
            'message' => self::progressMessage($action),
            'active' => true,
        ]);
    }

    /**
     * @deprecated Use shouldStyle() — kept for callers that still check the tenant panel only.
     */
    public static function onTenantPanel(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'tenant';
    }

    public static function shouldStyle(): bool
    {
        $panelId = Filament::getCurrentPanel()?->getId();

        return filled($panelId) && in_array($panelId, self::STYLED_PANELS, true);
    }

    private static function progressMessage(Action $action): string
    {
        if ($action instanceof AppAction && filled($message = $action->getLongRunningMessage())) {
            return $message;
        }

        return __('This may take a moment. Please keep this window open.');
    }

    private static function confirmationSubmitColor(Action $action): string
    {
        return match ($action->getColor()) {
            'danger' => 'danger',
            'warning' => 'warning',
            'success' => 'success',
            default => 'primary',
        };
    }

    private static function confirmationTone(Action $action): string
    {
        return match ($action->getColor()) {
            'danger' => 'danger',
            'warning' => 'warning',
            'success' => 'success',
            default => 'primary',
        };
    }

    private static function confirmWindowClasses(Action $action): string
    {
        $classes = [
            'ff-confirm-modal-window',
            'ff-confirm-modal-window--native',
            'ff-confirm-modal-window--' . self::confirmationTone($action),
        ];

        if (self::shouldShowProgress($action)) {
            $classes[] = 'ff-confirm-modal-window--long-running';
        }

        if (self::hasFormFields($action)) {
            $classes[] = 'ff-confirm-modal-window--with-fields';
        }

        return implode(' ', $classes);
    }

    private static function confirmationModalWidth(Action $action): Width
    {
        if (self::hasFormFields($action)) {
            return Width::Medium;
        }

        return self::confirmationTone($action) === 'danger' ? Width::ExtraSmall : Width::Small;
    }

    private static function hasFormFields(Action $action): bool
    {
        $schema = \Closure::bind(
            fn (): mixed => $this->schema,
            $action,
            $action,
        )();

        return $schema !== null;
    }
}
