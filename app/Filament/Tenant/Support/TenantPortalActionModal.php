<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Filament\Support\Action as AppAction;
use Filament\Actions\Action;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\View\View;

final class TenantPortalActionModal
{
    public static function applyConfirmation(Action $action): Action
    {
        $action = $action
            ->modalWidth(Width::Medium)
            ->extraModalWindowAttributes(['class' => 'ff-tenant-confirm-modal-window'], merge: true)
            ->modalIconColor(fn(): string|array => self::confirmationIconColor($action))
            ->modalSubmitAction(fn(Action $submit): Action => $submit
                ->color(self::confirmationSubmitColor($action))
                ->extraAttributes([
                    'wire:loading.attr' => 'disabled',
                    'wire:target' => 'callMountedAction',
                ], merge: true));

        if (self::shouldShowProgress($action)) {
            $action = self::applyProgressFooter($action);
        }

        return $action;
    }

    public static function applyProgressFooter(Action $action): Action
    {
        if ($action->hasModalContentFooter()) {
            return $action;
        }

        return $action->modalContentFooter(self::progressFooterView($action));
    }

    public static function shouldShowProgress(Action $action): bool
    {
        if ($action->isConfirmationRequired()) {
            return true;
        }

        if ($action instanceof AppAction && $action->isLongRunning()) {
            return true;
        }

        return false;
    }

    public static function progressFooterView(Action $action): View
    {
        return view('filament.tenant.partials.action-modal-progress', [
            'message' => self::progressMessage($action),
        ]);
    }

    private static function progressMessage(Action $action): string
    {
        if ($action instanceof AppAction && filled($message = $action->getLongRunningMessage())) {
            return $message;
        }

        return __('This may take a moment. Please keep this window open.');
    }

    /**
     * @return string | array<string>
     */
    private static function confirmationIconColor(Action $action): string|array
    {
        return match ($action->getColor()) {
            'danger' => 'danger',
            'warning' => 'warning',
            'success' => 'success',
            default => 'primary',
        };
    }

    private static function confirmationSubmitColor(Action $action): string
    {
        return match ($action->getColor()) {
            'danger' => 'danger',
            'warning' => 'warning',
            default => 'primary',
        };
    }
}
