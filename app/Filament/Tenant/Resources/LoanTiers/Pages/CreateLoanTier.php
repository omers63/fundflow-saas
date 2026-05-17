<?php

namespace App\Filament\Tenant\Resources\LoanTiers\Pages;

use App\Filament\Tenant\Resources\LoanTiers\LoanTierResource;
use Filament\Resources\Pages\CreateRecord;

class CreateLoanTier extends CreateRecord
{
    protected static string $resource = LoanTierResource::class;
}
