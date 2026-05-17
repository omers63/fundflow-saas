<?php

namespace App\Filament\Tenant\Resources\LoanTiers\Pages;

use App\Filament\Tenant\Resources\LoanTiers\LoanTierResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditLoanTier extends EditRecord
{
    protected static string $resource = LoanTierResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make(),
        ];
    }
}
