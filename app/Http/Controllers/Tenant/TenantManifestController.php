<?php

namespace App\Http\Controllers\Tenant;

use App\Support\PublicPageSettings;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

class TenantManifestController
{
    public function __invoke(): JsonResponse
    {
        $name = PublicPageSettings::fundName(tenant('name'));
        $logoUrl = PublicPageSettings::fundLogoUrl();

        return response()->json([
            'name' => $name,
            'short_name' => Str::limit($name, 12, ''),
            'description' => __('Manage family fund contributions, loans, and member accounts'),
            'start_url' => '/',
            'display' => 'standalone',
            'orientation' => 'any',
            'background_color' => '#059669',
            'theme_color' => '#059669',
            'categories' => ['finance', 'business'],
            'icons' => $this->icons($logoUrl),
        ]);
    }

    /**
     * @return list<array{src: string, sizes: string, type: string, purpose: string}>
     */
    private function icons(string $logoUrl): array
    {
        $type = str_ends_with($logoUrl, '.svg') ? 'image/svg+xml' : 'image/png';

        return collect([
            '72x72',
            '96x96',
            '128x128',
            '144x144',
            '152x152',
            '192x192',
            '384x384',
            '512x512',
        ])->map(fn (string $sizes): array => [
            'src' => $logoUrl,
            'sizes' => $sizes,
            'type' => $type,
            'purpose' => $sizes === '192x192' || $sizes === '512x512' ? 'any maskable' : 'any',
        ])->all();
    }
}
