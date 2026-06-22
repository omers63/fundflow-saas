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
                ? (self::isDangerConfirmation($action) ? Width::ExtraSmall : Width::Small)
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
     *     iconColor: string|array<string>
     * }
     */
    private static function confirmModalViewData(Action $action): array
    {
        return [
            'heading' => self::confirmationHeading($action),
            'description' => $action->getModalDescription(),
            'icon' => $action->getModalIcon(),
            'iconColor' => self::confirmationIconColor($action),
        ];
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

    /**
     * @return string | array<string>
     */
    private static function confirmationIconColor(Action $action): string|array
    {
        return $action->getModalIconColor() ?? 'primary';
    }

    private static function confirmationSubmitColor(Action $action): string
    {
        return match ($action->getColor()) {
            'danger' => 'danger',
            'warning' => 'warning',
            default => 'primary',
        };
    }

    private static function isDangerConfirmation(Action $action): bool
    {
        return $action->getColor() === 'danger';
    }

    private static function confirmWindowClasses(Action $action): string
    {
        $classes = ['ff-tenant-confirm-modal-window'];

        if (self::isDangerConfirmation($action)) {
            $classes[] = 'ff-tenant-confirm-modal-window--danger';
        }

        return implode(' ', $classes);
    }
}
