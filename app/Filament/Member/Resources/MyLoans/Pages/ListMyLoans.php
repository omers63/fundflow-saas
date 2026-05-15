<?php

namespace App\Filament\Member\Resources\MyLoans\Pages;

use App\Filament\Member\Resources\MyLoans\MyLoanResource;
use Filament\Resources\Pages\ListRecords;

class ListMyLoans extends ListRecords
{
    protected static string $resource = MyLoanResource::class;
}
