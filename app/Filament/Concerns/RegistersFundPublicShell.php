<?php

namespace App\Filament\Concerns;

use App\Support\ShowsFundPublicShell;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;

trait RegistersFundPublicShell
{
    /**
     * @var list<string>
     */
    private const TENANT_PANELS = ['tenant', 'member'];

    protected function registerFundPublicShell(Panel $panel): Panel
    {
        if (in_array($panel->getId(), self::TENANT_PANELS, true)) {
            return $this->registerTenantFilamentPublicChrome($panel);
        }

        if ($panel->getId() === 'admin') {
            return $this->registerCentralFilamentAuthChrome($panel);
        }

        return $panel;
    }

    protected function registerTenantFilamentPublicChrome(Panel $panel): Panel
    {
        return $panel
            ->renderHook(PanelsRenderHook::HEAD_END, function (): HtmlString {
                if (! ShowsFundPublicShell::onTenantFilamentAuthPage()) {
                    return new HtmlString('');
                }

                return new HtmlString(view('partials.filament-tenant-public-nav-assets')->render());
            })
            ->renderHook(PanelsRenderHook::BODY_START, function (): HtmlString {
                if (! ShowsFundPublicShell::onTenantFilamentAuthPage()) {
                    return new HtmlString('');
                }

                return new HtmlString(view('partials.filament-tenant-public-chrome-start')->render());
            });
    }

    protected function registerCentralFilamentAuthChrome(Panel $panel): Panel
    {
        return $panel
            ->renderHook(PanelsRenderHook::HEAD_END, function (): HtmlString {
                if (! ShowsFundPublicShell::onCentralFilamentAuthPage()) {
                    return new HtmlString('');
                }

                return new HtmlString(view('partials.filament-central-auth-assets')->render());
            })
            ->renderHook(PanelsRenderHook::BODY_START, function (): HtmlString {
                if (! ShowsFundPublicShell::onCentralFilamentAuthPage()) {
                    return new HtmlString('');
                }

                return new HtmlString(view('partials.filament-central-auth-chrome-start')->render());
            });
    }
}
