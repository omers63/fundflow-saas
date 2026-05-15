<?php

namespace App\Support;

use Illuminate\Support\Facades\Storage;

final class TenantAssetUrl
{
    public static function publicDisk(string $path): string
    {
        $path = ltrim($path, '/');

        if (tenant()) {
            return url('/tenancy/assets/'.$path);
        }

        return Storage::disk('public')->url($path);
    }

    public static function publicDiskExists(string $path): bool
    {
        return Storage::disk('public')->exists(ltrim($path, '/'));
    }
}
