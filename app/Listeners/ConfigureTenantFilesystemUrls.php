<?php

namespace App\Listeners;

use Illuminate\Support\Facades\Storage;
use Stancl\Tenancy\Events\RevertedToCentralContext;
use Stancl\Tenancy\Events\TenancyBootstrapped;

class ConfigureTenantFilesystemUrls
{
    private static ?string $originalPublicDiskUrl = null;

    public function handleTenancyBootstrapped(TenancyBootstrapped $event): void
    {
        self::$originalPublicDiskUrl ??= config('filesystems.disks.public.url');

        config([
            'filesystems.disks.public.url' => rtrim(url('/tenancy/assets'), '/'),
        ]);

        Storage::forgetDisk('public');
    }

    public function handleRevertedToCentralContext(RevertedToCentralContext $event): void
    {
        if (self::$originalPublicDiskUrl === null) {
            return;
        }

        config([
            'filesystems.disks.public.url' => self::$originalPublicDiskUrl,
        ]);

        Storage::forgetDisk('public');
    }
}
