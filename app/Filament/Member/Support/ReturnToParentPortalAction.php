<?php

namespace App\Filament\Member\Support;

use App\Services\Tenant\ImpersonationService;
use Filament\Actions\Action;
use Filament\Facades\Filament;
use Filament\Notifications\Notification;
use Livewire\Component;

final class ReturnToParentPortalAction
{
    public static function isImpersonating(): bool
    {
        return session()->has('impersonator_user_id');
    }

    public static function make(?Component $livewire = null): Action
    {
        return Action::make('return_to_parent_portal')
            ->label(__('Return to parent portal'))
            ->icon('heroicon-o-arrow-uturn-left')
            ->color('warning')
            ->visible(fn (): bool => self::isImpersonating())
            ->requiresConfirmation()
            ->modalDescription(__('You will switch back to the parent household portal.'))
            ->action(function () use ($livewire): void {
                if (! app(ImpersonationService::class)->stop()) {
                    return;
                }

                Notification::make()
                    ->title(__('Returned to parent portal.'))
                    ->success()
                    ->send();

                $url = Filament::getPanel('member')?->getUrl() ?? '/member';

                if ($livewire instanceof Component) {
                    $livewire->redirect($url, navigate: false);
                }
            });
    }
}
