<?php

namespace App\Filament\Tenant\Resources\FundTiers\Pages;

use App\Filament\Tenant\Resources\FundTiers\FundTierResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditFundTier extends EditRecord
{
    protected static string $resource = FundTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
