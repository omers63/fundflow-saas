<?php

namespace App\Filament\Tenant\Resources\CashOutRequests\Pages;

use App\Filament\Tenant\Resources\CashOutRequests\CashOutRequestResource;
use Filament\Resources\Pages\ListRecords;

class ListCashOutRequests extends ListRecords
{
    protected static string $resource = CashOutRequestResource::class;

    public function getSubheading(): ?string
    {
        return __('Review member withdrawal requests, debit cash on approval, and clear against bank imports later.');
    }
}
