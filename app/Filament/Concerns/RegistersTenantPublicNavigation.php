<?php

namespace App\Filament\Concerns;

use App\Support\ShowsTenantPublicNavigation;
use Filament\Panel;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;

trait RegistersTenantPublicNavigation
{
    protected function registerTenantPublicNavigation(Panel $panel): Panel
    {
        return $panel
            ->renderHook(PanelsRenderHook::HEAD_END, function (): HtmlString {
                if (! ShowsTenantPublicNavigation::onFilamentAuthPage()) {
                    return new HtmlString('');
                }

                return new HtmlString(view('partials.filament-tenant-public-nav-assets')->render());
            })
            ->renderHook(PanelsRenderHook::BODY_START, function (): HtmlString {
                if (! ShowsTenantPublicNavigation::onFilamentAuthPage()) {
                    return new HtmlString('');
                }

                return new HtmlString(view('components.tenant-public-nav')->render());
            })
            ->renderHook(PanelsRenderHook::BODY_END, function (): HtmlString {
                if (! ShowsTenantPublicNavigation::onFilamentAuthPage()) {
                    return new HtmlString('');
                }

                return new HtmlString(view('components.tenant-public-footer')->render());
            });
    }
}
