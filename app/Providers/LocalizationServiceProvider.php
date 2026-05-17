<?php

declare(strict_types=1);

namespace App\Providers;

use App\Models\Tenant\User;
use App\Support\AppLocale;
use App\Support\ShowsFundPublicShell;
use BezhanSalleh\LanguageSwitch\Enums\Placement;
use BezhanSalleh\LanguageSwitch\Events\LocaleChanged;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class LocalizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->configureLanguageSwitch();
        $this->listenForLocaleChanges();
    }

    protected function configureLanguageSwitch(): void
    {
        LanguageSwitch::configureUsing(function (LanguageSwitch $switch): void {
            $switch
                ->locales(AppLocale::SUPPORTED)
                ->labels([
                    'ar' => 'العربية',
                    'en' => 'English',
                ])
                ->flags([
                    'en' => 'https://flagcdn.com/w40/gb.png',
                    'ar' => 'https://flagcdn.com/w40/sa.png',
                ])
                ->flagsOnly()
                ->circular()
                ->visible(
                    insidePanels: true,
                    outsidePanels: fn (): bool => ! ShowsFundPublicShell::onTenantFilamentAuthPage()
                    && ! ShowsFundPublicShell::onCentralFilamentAuthPage(),
                )
                ->outsidePanelRoutes([
                    'auth.login',
                    'auth.register',
                    'auth.password-reset.request',
                    'auth.password-reset.reset',
                ])
                ->outsidePanelPlacement(Placement::TopRight)
                ->renderHook(PanelsRenderHook::USER_MENU_BEFORE)
                ->userPreferredLocale(function (): ?string {
                    $user = auth()->user();

                    if ($user instanceof User) {
                        return $user->preferredLocale();
                    }

                    return null;
                });
        });
    }

    protected function listenForLocaleChanges(): void
    {
        Event::listen(function (LocaleChanged $event): void {
            session()->put('locale', $event->locale);

            $user = auth()->user();

            if (! $user instanceof User || ! AppLocale::isSupported($event->locale)) {
                return;
            }

            if ($user->preferred_locale === $event->locale) {
                return;
            }

            $user->forceFill(['preferred_locale' => $event->locale])->save();
        });
    }
}
