<?php

namespace App\Http\Controllers\Tenant;

use App\Support\BrandAppearanceSettings;
use App\Support\PublicPageContentSettings;
use App\Support\PublicPageSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class TenantManifestController
{
    public function __invoke(): JsonResponse
    {
        $name = PublicPageSettings::fundName(tenant('name'));
        $shortName = trim(PublicPageContentSettings::text('pwa_short_name'));

        if ($shortName === '') {
            $shortName = Str::limit($name, 12, '');
        }

        return response()
            ->json([
                'name' => $name,
                'short_name' => $shortName,
                'description' => PublicPageContentSettings::text('pwa_description'),
                'start_url' => '/',
                'display' => 'standalone',
                'orientation' => 'any',
                'background_color' => BrandAppearanceSettings::backgroundColor(),
                'theme_color' => BrandAppearanceSettings::themeColor(),
                'categories' => ['finance', 'business'],
                'icons' => BrandAppearanceSettings::pwaManifestIcons(),
            ])
            ->header('Cache-Control', 'no-cache, must-revalidate');
    }
}
