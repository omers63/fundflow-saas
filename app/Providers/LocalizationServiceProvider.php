<?php

declare(strict_types=1);

namespace App\Providers;

use App\Http\Livewire\LanguageSwitchComponent;
use App\Http\Livewire\TenantTopbarLanguageSwitch;
use App\Support\AppLocale;
use App\Support\ShowsFundPublicShell;
use App\Support\TenantAuthUser;
use App\Support\UserPreferredLocale;
use BezhanSalleh\LanguageSwitch\Enums\Placement;
use BezhanSalleh\LanguageSwitch\Events\LocaleChanged;
use BezhanSalleh\LanguageSwitch\LanguageSwitch;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Livewire\Livewire;

class LocalizationServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Livewire::component('language-switch-component', LanguageSwitchComponent::class);
        Livewire::component('tenant-topbar-language-switch', TenantTopbarLanguageSwitch::class);

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
                ->circular()
                ->flagsOnly()
                ->excludes(['tenant', 'member'])
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
                    $user = TenantAuthUser::user();

                    return $user?->preferredLocale();
                });
        });
    }

    protected function listenForLocaleChanges(): void
    {
        Event::listen(function (LocaleChanged $event): void {
            session()->put('locale', $event->locale);

            if (! AppLocale::isSupported($event->locale)) {
                return;
            }

            $user = TenantAuthUser::user();

            if ($user === null) {
                return;
            }

            UserPreferredLocale::persist($user, $event->locale);
        });
    }
}
