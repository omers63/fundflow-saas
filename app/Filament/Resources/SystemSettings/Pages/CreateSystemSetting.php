<?php

namespace App\Filament\Resources\SystemSettings\Pages;

use App\Filament\Resources\SystemSettings\SystemSettingResource;
use App\Support\SystemSettings;
use Filament\Resources\Pages\CreateRecord;

class CreateSystemSetting extends CreateRecord
{
    protected static string $resource = SystemSettingResource::class;

    protected function afterCreate(): void
    {
        SystemSettings::clearCache();
    }
}
