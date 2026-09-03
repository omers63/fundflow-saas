<?php

declare(strict_types=1);

namespace App\Filament\Member\Support;

use App\Services\Tenant\ImpersonationService;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Livewire\Component;

final class ReturnToParentPortalAction
{
    public static function isImpersonating(): bool
    {
                return app(ImpersonationService::class)->isActive();
    }

    public static function make(?Component $livewire = null): Action
    {
        $impersonation = app(ImpersonationService::class);

        return Action::make('return_to_parent_portal')
            ->label(fn(): string => $impersonation->returnActionLabel())
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (): bool => self::isImpersonating())
            ->requiresConfirmation()
            ->modalDescription(fn(): string => $impersonation->returnModalDescription())
            ->action(function (Action $action) use ($impersonation): void {
                $returnUrl = $impersonation->returnUrl();
                $successTitle = $impersonation->returnSuccessTitle();

                if (!$impersonation->stop()) {
                    return;
                }

                Notification::make()
                    ->title($successTitle)
                    ->success()
                    ->send();

                // Full document navigation — SPA soft-nav after an auth swap leaves
                // Livewire on a stale fingerprint and can 419.
                $action->redirect($returnUrl, navigate: false);
            });
    }
}
