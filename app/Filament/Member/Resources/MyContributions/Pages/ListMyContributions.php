<?php

namespace App\Filament\Member\Resources\MyContributions\Pages;

use App\Filament\Member\Resources\MyContributions\MyContributionResource;
use Filament\Resources\Pages\ListRecords;

class ListMyContributions extends ListRecords
{
    protected static string $resource = MyContributionResource::class;
}
