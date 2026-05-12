<?php

namespace App\Filament\Resources\SystemSettings\Pages;

use App\Filament\Resources\SystemSettings\SystemSettingResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;
use App\Models\SystemSetting;

class ListSystemSettings extends ListRecords
{
    protected static string $resource = SystemSettingResource::class;

    protected function getHeaderActions(): array
    {
        $canCreate = !SystemSetting::query()->exists();

        return [
            CreateAction::make()->visible($canCreate),
        ];
    }
}
