<?php

namespace App\Providers\Filament;

use App\Filament\Concerns\RegistersFundPublicShell;
use App\Filament\Member\Pages\MemberDashboard;
use App\Filament\Member\Pages\MyProfilePage;
use App\Filament\Member\Support\MemberNavigation;
use App\Filament\Member\Support\ReturnToParentPortalAction;
use App\Filament\Support\DatabaseNotificationsRefresh;
use App\Http\Middleware\AuthenticateMemberPanel;
use App\Http\Middleware\SetFilamentPanelAuthGuard;
use App\Livewire\Tenant\MemberLoginPage;
use App\Support\PublicPageSettings;
use Filament\Actions\Action;
use Filament\Http\Middleware\AuthenticateSession;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\Support\Colors\Color;
use Filament\View\PanelsRenderHook;
use Illuminate\Contracts\View\View;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\VerifyCsrfToken;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\HtmlString;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use Stancl\Tenancy\Middleware\InitializeTenancyByDomain;
use Stancl\Tenancy\Middleware\PreventAccessFromCentralDomains;

class MemberPanelProvider extends PanelProvider
{
    use RegistersFundPublicShell;

    public function panel(Panel $panel): Panel
    {
        return $this->registerFundPublicShell($panel
            ->id('member')
            ->path('member')
            ->authGuard('tenant')
            ->login(MemberLoginPage::class)
            ->disabledErrorNotification(419)
            ->disabledErrorNotification(401)
            ->viteTheme('resources/css/filament/member/theme.css')
            ->colors([
                'primary' => Color::Emerald,
            ])
            ->brandName(fn (): string => PublicPageSettings::fundName(tenant('name')))
            ->favicon(fn (): string => PublicPageSettings::fundLogoUrl())
            ->brandLogo(fn (): string => PublicPageSettings::fundPanelBrandLogoUrl())
            ->darkModeBrandLogo(fn (): string => PublicPageSettings::fundPanelBrandLogoUrl())
            ->brandLogoHeight(PublicPageSettings::BRAND_LOGO_HEIGHT)
            ->sidebarCollapsibleOnDesktop()
            ->sidebarFullyCollapsibleOnDesktop()
            ->navigationGroups([
                MemberNavigation::GROUP_MY_FINANCE => NavigationGroup::make()
                    ->label(fn (): string => MemberNavigation::groupLabel(MemberNavigation::GROUP_MY_FINANCE)),
                MemberNavigation::GROUP_LOANS => NavigationGroup::make()
                    ->label(fn (): string => MemberNavigation::groupLabel(MemberNavigation::GROUP_LOANS)),
                MemberNavigation::GROUP_SETTINGS => NavigationGroup::make()
                    ->label(fn (): string => MemberNavigation::groupLabel(MemberNavigation::GROUP_SETTINGS)),
            ])
            ->userMenuItems([
                ReturnToParentPortalAction::make()
                    ->sort(-20),
                Action::make('profile')
                    ->label(fn (): string => __('My profile'))
                    ->icon('heroicon-o-user-circle')
                    ->url(fn (): string => MyProfilePage::getUrl())
                    ->sort(-1),
            ])
            ->renderHook(
                PanelsRenderHook::TOPBAR_START,
                fn (): View => view('filament.member.impersonation-topbar-banner'),
            )
            ->discoverResources(in: app_path('Filament/Member/Resources'), for: 'App\\Filament\\Member\\Resources')
            ->discoverPages(in: app_path('Filament/Member/Pages'), for: 'App\\Filament\\Member\\Pages')
            ->pages([
                MemberDashboard::class,
            ])
            ->discoverWidgets(in: app_path('Filament/Member/Widgets'), for: 'App\\Filament\\Member\\Widgets')
            ->widgets([])
            ->databaseNotifications(isLazy: false)
            ->databaseNotificationsPolling(DatabaseNotificationsRefresh::pollingInterval())
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => new HtmlString(
                view('partials.arabic-fonts')->render()
                .view('partials.arabic-display-body-class')->render()
                .view('partials.pwa-head')->render()
            ))
            ->renderHook(PanelsRenderHook::BODY_END, fn (): HtmlString => new HtmlString(view('partials.livewire-session-recovery')->render()))
            ->renderHook(PanelsRenderHook::BODY_END, fn (): HtmlString => new HtmlString(view('partials.pwa-sw')->render()))
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                SetFilamentPanelAuthGuard::class,
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
                AuthenticateMemberPanel::class,
            ]));
    }
}
