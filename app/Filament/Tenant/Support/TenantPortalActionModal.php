<?php

declare(strict_types=1);

namespace App\Filament\Tenant\Support;

use App\Filament\Support\Action as AppAction;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;

final class TenantPortalActionModal
{
    public static function applyConfirmation(Action $action): Action
    {
        if (! $action->hasModalContent()) {
            $action = $action->modalContent(
                fn (Action $action): ?View => self::onTenantPanel()
                ? view(
                    'filament.tenant.partials.action-confirm-modal',
                    self::confirmModalViewData($action),
                )
                : null,
            );
        }

        $action = $action
            ->modalWidth(fn (): Width|string => self::onTenantPanel()
                ? self::confirmationModalWidth($action)
                : Width::Medium)
            ->extraModalWindowAttributes(
                fn (): array => self::onTenantPanel()
                ? ['class' => self::confirmWindowClasses($action)]
                : [],
                merge: true,
            )
            ->modalSubmitAction(function (Action $submit) use ($action): Action {
                if (! self::onTenantPanel()) {
                    return $submit;
                }

                return $submit
                    ->color(self::confirmationSubmitColor($action))
                    ->extraAttributes([
                        'wire:loading.attr' => 'disabled',
                        'wire:target' => 'callMountedAction',
                    ], merge: true);
            });

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

        return $action->modalContentFooter(
            fn (Action $action): ?View => self::onTenantPanel() && self::shouldShowProgress($action)
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
        return view('filament.tenant.partials.action-modal-progress', [
            'message' => self::progressMessage($action),
        ]);
    }

    public static function onTenantPanel(): bool
    {
        return Filament::getCurrentPanel()?->getId() === 'tenant';
    }

    /**
     * @return array{
     *     heading: string|Htmlable,
     *     description: string|Htmlable|null,
     *     icon: mixed,
     *     iconColor: string|array<string>,
     *     tone: string
     * }
     */
    private static function confirmModalViewData(Action $action): array
    {
        $tone = self::confirmationTone($action);

        return [
            'heading' => self::confirmationHeading($action),
            'description' => $action->getModalDescription(),
            'icon' => self::confirmationIcon($action),
            'iconColor' => $action->getModalIconColor() ?? $tone,
            'tone' => $tone,
        ];
    }

    private static function confirmationIcon(Action $action): mixed
    {
        if (self::confirmationTone($action) === 'danger') {
            return 'heroicon-o-exclamation-triangle';
        }

        $icon = $action->getIcon();

        if (filled($icon)) {
            return $icon;
        }

        return match (self::confirmationTone($action)) {
            'warning' => 'heroicon-o-exclamation-circle',
            default => 'heroicon-o-question-mark-circle',
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

    private static function confirmationHeading(Action $action): string|Htmlable
    {
        if ($action->hasCustomModalHeading()) {
            return $action->getCustomModalHeading();
        }

        return $action->getLabel();
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

    private static function isDangerConfirmation(Action $action): bool
    {
        return self::confirmationTone($action) === 'danger';
    }

    private static function confirmWindowClasses(Action $action): string
    {
        $classes = [
            'ff-tenant-confirm-modal-window',
            'ff-tenant-confirm-modal-window--'.self::confirmationTone($action),
        ];

        if (self::shouldShowProgress($action)) {
            $classes[] = 'ff-tenant-confirm-modal-window--long-running';
        }

        if (self::hasFormFields($action)) {
            $classes[] = 'ff-tenant-confirm-modal-window--with-fields';
        }

        return implode(' ', $classes);
    }

    private static function confirmationModalWidth(Action $action): Width
    {
        if (self::hasFormFields($action)) {
            return Width::Medium;
        }

        return self::isDangerConfirmation($action) ? Width::ExtraSmall : Width::Small;
    }

    /**
     * Confirmations that embed selects / date pickers need a wider, overflow-safe window.
     */
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
