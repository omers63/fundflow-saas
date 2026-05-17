<?php

namespace App\Providers\Filament;

use App\Filament\Concerns\RegistersTenantPublicNavigation;
use App\Filament\Tenant\Pages\Dashboard;
use App\Livewire\Tenant\TenantAdminLoginPage;
use App\Support\PublicPageSettings;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
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
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

class TenantPanelProvider extends PanelProvider
{
    use RegistersTenantPublicNavigation;

    public function panel(Panel $panel): Panel
    {
        return $this->registerTenantPublicNavigation($panel)
            ->id('tenant')
            ->path('admin')
            ->authGuard('tenant')
            ->login(TenantAdminLoginPage::class)
            ->disabledErrorNotification(419)
            ->disabledErrorNotification(401)
            ->viteTheme('resources/css/filament/tenant/theme.css')
            ->colors([
                'primary' => Color::Sky,
            ])
            ->brandName(fn (): string => PublicPageSettings::fundName(tenant('name')))
            ->favicon(fn (): string => PublicPageSettings::fundLogoUrl())
            ->brandLogo(fn (): string => PublicPageSettings::fundPanelBrandLogoUrl())
            ->darkModeBrandLogo(fn (): string => PublicPageSettings::fundPanelBrandLogoUrl())
            ->brandLogoHeight(PublicPageSettings::BRAND_LOGO_HEIGHT)
            ->sidebarCollapsibleOnDesktop()
            ->sidebarFullyCollapsibleOnDesktop()
            ->discoverResources(in: app_path('Filament/Tenant/Resources'), for: 'App\\Filament\\Tenant\\Resources')
            ->discoverClusters(in: app_path('Filament/Tenant/Clusters'), for: 'App\\Filament\\Tenant\\Clusters')
            ->discoverPages(in: app_path('Filament/Tenant/Pages'), for: 'App\\Filament\\Tenant\\Pages')
            ->pages([
                Dashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Tenant/Widgets'), for: 'App\\Filament\\Tenant\\Widgets')
            ->widgets([])
            ->databaseNotifications()
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
                InitializeTenancyByDomain::class,
                PreventAccessFromCentralDomains::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ]);
    }
}
