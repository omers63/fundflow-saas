<?php

namespace App\Providers\Filament;

use App\Filament\Concerns\RegistersFundPublicShell;
use App\Filament\Widgets\MyTenants;
use App\Filament\Widgets\StatsOverview;
use App\Filament\Widgets\TenantGrowthChart;
use App\Support\FundflowBrand;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Pages;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;

class AdminPanelProvider extends PanelProvider
{
    use RegistersFundPublicShell;

    public function panel(Panel $panel): Panel
    {
        return $this->registerFundPublicShell($panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->domains([
                config('tenancy.central_domain'),

                // you can add more domains here and adjusting the tenancy config for multiple central domains
            ])
            ->viteTheme('resources/css/filament/admin/theme.css')
            ->login()
            ->disabledErrorNotification(419)
            ->disabledErrorNotification(401)
            ->registration() // Enabled registration
            ->colors([
                'primary' => Color::Rose,
            ])
            ->favicon(FundflowBrand::faviconUrl())
            ->brandLogo(FundflowBrand::panelLogoUrl())
            ->darkModeBrandLogo(FundflowBrand::panelLogoUrl())
            ->brandLogoHeight('5rem')
            ->sidebarCollapsibleOnDesktop()
            ->sidebarFullyCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Resources'), for: 'App\\Filament\\Resources')
            ->discoverPages(in: app_path('Filament/Pages'), for: 'App\\Filament\\Pages')
            ->pages([
                Pages\Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Widgets'), for: 'App\\Filament\\Widgets')
            ->widgets([
                StatsOverview::class,
                MyTenants::class,
                TenantGrowthChart::class,
            ])
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => new HtmlString(view('partials.pwa-head')->render()))
            ->renderHook(PanelsRenderHook::BODY_END, fn (): HtmlString => new HtmlString(view('partials.livewire-session-recovery')->render()))
            ->renderHook(PanelsRenderHook::BODY_END, fn (): HtmlString => new HtmlString(view('partials.pwa-sw')->render()))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                VerifyCsrfToken::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->plugins([
                FilamentShieldPlugin::make(),
            ]));
    }
}
