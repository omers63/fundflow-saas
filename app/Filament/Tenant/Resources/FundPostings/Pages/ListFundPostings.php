<?php

namespace App\Filament\Tenant\Resources\FundPostings\Pages;

use App\Filament\Tenant\Resources\FundPostings\FundPostingResource;
use Filament\Resources\Pages\ListRecords;

class ListFundPostings extends ListRecords
{
    protected static string $resource = FundPostingResource::class;
}
