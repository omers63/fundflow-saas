<?php

namespace App\Filament\Tenant\Resources\Contributions\Pages;

use App\Filament\Tenant\Resources\Contributions\ContributionResource;
use Filament\Resources\Pages\CreateRecord;

class CreateContribution extends CreateRecord
{
    protected static string $resource = ContributionResource::class;
}
